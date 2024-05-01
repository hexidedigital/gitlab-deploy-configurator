<?php

namespace App\Http\Telegram;

use App\Domains\DeployConfigurator\CiCdTemplateRepository;
use App\Domains\DeployConfigurator\Data\CiCdOptions;
use App\Domains\DeployConfigurator\Data\ProjectDetails;
use App\Domains\DeployConfigurator\Data\Stage\SshOptions;
use App\Domains\DeployConfigurator\Data\Stage\StageOptions;
use App\Domains\DeployConfigurator\Data\TemplateGroup;
use App\Domains\DeployConfigurator\Data\TemplateInfo;
use App\Domains\DeployConfigurator\DeployConfigBuilder;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Server;
use App\Domains\DeployConfigurator\DeployProjectBuilder;
use App\Domains\DeployConfigurator\Jobs\ConfigureRepositoryJob;
use App\Domains\DeployConfigurator\ServerDetailParser;
use App\Domains\GitLab\Data\ProjectData;
use App\Exceptions\Telegram\Halt;
use App\Exceptions\Telegram\MissingUserException;
use App\Filament\Dashboard\Pages\DeployConfigurator\SampleFormData;
use App\Models\CallbackButton;
use Closure;
use DefStudio\Telegraph\Client\TelegraphResponse;
use DefStudio\Telegraph\DTO\Chat;
use DefStudio\Telegraph\Enums\ChatActions;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\ReplyKeyboard;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Telegraph;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

use function Pest\Laravel\json;

class DeployWebhookHandler extends WebhookHandler
{
    use WithChatContext;

    use Command\StartCommand;
    use Command\CancelCommand;
    use Command\HelpCommand;

    use Concerns\WithProjectInfoManage;

    protected array $commands = [
        'start',
        'help',
        'cancel',
        'startconfiguration',
        'retry',
        'restart',
        'step',
        'back',
    ];

    protected array $callbacks = [
        'selectProjectCallback',
        'configureCiCdOptionsCallback',
        'configureDeploymentSettingsCallback',
        'handleConfirmationCallback',
    ];

    protected function welcomeMessage(): void
    {
        $this->reply(
            "Hello! To get started with me, you should connect your Telegram account to the Deploy Configurator." .
            "\n\nTo do this, go to the Deploy Configurator website and scan the QR code from the Profile page to connect your Telegram account." .
            "\n\nIf you don't have an account for the Deploy Configurator, create one at " . url('/')
        );
    }

    public function handle(Request $request, TelegraphBot $bot): void
    {
        try {
            parent::handle($request, $bot);
        } finally {
            if (isset($this->chatContext)) {
                $this->chatContext->save();
            }
        }
    }

    protected function canHandle(string $action): bool
    {
        if (!parent::canHandle($action)) {
            return false;
        }

        return in_array($action, array_merge($this->commands, $this->callbacks));
    }

    protected function handleUnknownCommand(Stringable $text): void
    {
        if ($this->message?->chat()?->type() === Chat::TYPE_PRIVATE) {
            $this->makePreparationsForWork();

            $this->chat->message("Sorry, but I don't understand this command. To see a list of available commands, please use the /help command.")->send();
        }
    }

    protected function onFailure(Throwable $throwable): void
    {
        if ($throwable instanceof Halt) {
            return;
        }

        if ($throwable instanceof MissingUserException) {
            $this->welcomeMessage();

            return;
        }

        if ($throwable instanceof NotFoundHttpException) {
            throw $throwable;
        }

        report($throwable);

        $this->chat->message('Sorry man, I failed 🤕')->send();

        if ($this->user->isRoot()) {
            $this->chat->message($throwable->getMessage())->send();
        }
    }

    // ------------------------------------
    // Debug commands
    // ------------------------------------

    protected function asserHasActiveCommand(): void
    {
        if ($this->chatContext->current_command) {
            return;
        }

        $this->chat->message('💤 No active step')->removeReplyKeyboard()->send();

        throw new Halt();
    }

    public function retry(): void
    {
        $this->makePreparationsForWork();

        $this->asserHasActiveCommand();

        $this->executeCommandPrompt($this->chatContext->current_command);
    }

    public function restart(): void
    {
        $this->makePreparationsForWork();

        if ($this->chatContext->current_command) {
            $this->cancel();
        }
        $this->startconfiguration();
    }

    public function step(): void
    {
        $this->makePreparationsForWork();

        $this->asserHasActiveCommand();

        $command = str($this->chatContext->current_command)->kebab()->replace(['-', '_'], ' ')->lower();

        $this->chat->markdown("🔗 Current step: *{$command}*")->send();
    }

    public function back(): void
    {
        $this->makePreparationsForWork();

        $this->asserHasActiveCommand();

        $this->chat->message('↩ Going back...')->send();

        $this->switchBackFrom($this->chatContext->current_command);
    }

    // ------------------------------------
    // Helpers
    // ------------------------------------

    protected function loading(): void
    {
        $this->chat->action(ChatActions::TYPING)->send();
    }

    protected function makeCallbackButton(string $label, string $action, array $payload): Button
    {
        return Button::make($label)->action($action)->param('c_id', $this->callbackPayload($payload));
    }

    protected function callbackPayload(array $payload): int
    {
        $callbackButton = $this->chatContext->callbackButtons()->create([
            'payload' => $payload,
        ]);

        return $callbackButton->getKey();
    }

    protected function assertCallback(): void
    {
        if (!$this->callbackQuery) {
            throw new Halt();
        }
    }

    // ------------------------------------
    // Main flow processing
    // ------------------------------------

    protected function handleChatMessage(Stringable $text): void
    {
        $this->makePreparationsForWork();

        if (!$this->chatContext->current_command) {
            $this->help();

            return;
        }

        match ($this->chatContext->current_command) {
            default => $this->help(),
            'selecting_project' => $this->filterProjectByTerm($text),
            'selecting_branch' => $this->selectingBranch($text),
            'selecting_ci_cd_options' => $this->processCiCdInput($text),
            'access_parsing' => $this->processAccessParsingInput($text),
            'deployment_settings' => $this->processDeploymentSettingsInput($text),
        };
    }

    protected function switchBackFrom(string $currentCommand): void
    {
        $prevCommand = match ($this->chatContext->current_command) {
            'selecting_project' => 'start_configuration',
            'selecting_branch' => 'selecting_project',
            'selecting_ci_cd_options' => 'selecting_branch',
            'access_parsing' => 'selecting_ci_cd_options',
            'deployment_settings' => 'access_parsing',
            default => $this->chatContext->current_command,
        };

        if ($this->user->isRoot()) {
            $this->chat->message("{$prevCommand} ⬅ {$currentCommand}")->send();
        }

        $this->chatContext->fill([
            'current_command' => $prevCommand,
        ]);

        $this->executeCommandPrompt($prevCommand);
    }

    protected function switchToNextCommandFromCommand(string $currentCommand): void
    {
        $nextCommand = match ($currentCommand) {
            'start_configuration' => 'selecting_project',
            'selecting_project' => 'selecting_branch',
            'selecting_branch' => 'selecting_ci_cd_options',
            'selecting_ci_cd_options' => 'access_parsing',
            'access_parsing' => 'deployment_settings',
            'deployment_settings' => 'confirmation',
            default => $currentCommand,
        };

        if ($this->user->isRoot()) {
            $this->chat->message("{$currentCommand} ➡ {$nextCommand}")->send();
        }

        $this->chatContext->fill([
            'current_command' => $nextCommand,
        ]);

        $this->executeCommandPrompt($nextCommand);
    }

    protected function executeCommandPrompt(?string $command): void
    {
        match ($command) {
            'selecting_project' => $this->promptToSelectProject(),
            'selecting_branch' => $this->promptToSelectBranchName(),
            'selecting_ci_cd_options' => $this->promptToConfigureCiCdOptions(),
            'access_parsing' => $this->promptForAccessInput(),
            'deployment_settings' => $this->promptForDeploymentSettings(),
            'confirmation' => $this->promptForConfirmation(),
            default => throw new Halt(),
        };
    }

    protected function assertCurrentCommand(?string $commandName, Closure|string $failure): void
    {
        if ($this->chatContext->current_command === $commandName) {
            return;
        }

        if ($failure instanceof Closure) {
            $failure();
        } else {
            $this->chat->message($failure)->send();
        }

        throw new Halt();
    }

    protected function processCallbackFrom(?int $messageId): void
    {
        $this->chatContext->pushToState([
            'processCallbackFrom' => $messageId,
        ]);
    }

    // ------------------------------------
    // Start configuration
    // ------------------------------------

    public function startconfiguration(): void
    {
        $this->makePreparationsForWork();
        $this->assertCurrentCommand(null, 'Sorry, but you have already started the configuration process. If you want to start over, please use the /cancel command.');

        $this->resetChatContext();

        $this->chatContext->pushToState(['openedAt' => now()]);

        $this->chat->message('Alright, let\'s start configuring the deployment 🛠. Firstly, select project 🦊')->removeReplyKeyboard()->send();

        $this->switchToNextCommandFromCommand('start_configuration');
    }

    protected function promptToSelectProject(): void
    {
        $response = $this->chat->message('Connecting to GitLab with your gitlab token...')->send();

        $this->loading();

        $gitLabProjects = $this->gitLabService->fetchProjectFromGitLab(['per_page' => 10]);

        if ($gitLabProjects->isEmpty()) {
            $this->chat->message('Sorry, I couldn\'t find any projects for you. Please check your GitLab token and try again.')->send();

            return;
        }

        $telegraphResponse = $this->chat
            ->message("Please, select project from list bellow, there is last 10 projects.")
            ->keyboard($this->buildKeyboardForProjects($gitLabProjects))
            ->send();

        $this->chat
            ->message("If you can't find your project in list, please just type the name of the project and I will try to find it.")
            ->replyKeyboard($this->replyKeyboardForProjects())
            ->send();

        $this->chat->deleteMessage($response->telegraphMessageId())->send();

        $this->processCallbackFrom($telegraphResponse->telegraphMessageId());
    }

    protected function replyKeyboardForProjects(): ReplyKeyboard
    {
        return ReplyKeyboard::make()
            ->button('frontend.')->button('web.')->button('backend.')
            ->inputPlaceholder('🔍 Enter search term...')
            ->oneTime()
            ->chunk(3)
            ->resize();
    }

    protected function filterProjectByTerm(Stringable $text): void
    {
        $this->chat->markdown("🔍 looking projects projects by *{$text}* ...")->removeReplyKeyboard()->send();

        $this->loading();

        $gitLabProjects = $this->gitLabService->fetchProjectFromGitLab(['per_page' => 10, 'search' => $text]);

        $this->processCallbackFrom(null);

        if ($gitLabProjects->isEmpty()) {
            $this->chat->message("Sorry, I couldn't find any projects for you 🤷. Please check your GitLab token and try again.")->send();

            return;
        }

        $telegraphResponse = $this->chat
            ->message('I found some projects, please select one from the list bellow:')
            ->keyboard($this->buildKeyboardForProjects($gitLabProjects))
            ->send();

        $this->processCallbackFrom($telegraphResponse->telegraphMessageId());
    }

    protected function buildKeyboardForProjects(Collection $records): Keyboard
    {
        if ($records->isEmpty()) {
            $this->chat->message('Sorry, no records found. Please try again.')->send();

            return Keyboard::make()->buttons([]);
        }

        return Keyboard::make()->buttons(
            $records->map(
                fn (ProjectData $record) => Button::make(sprintf("👉 %s", $record->name))
                    ->action('selectProjectCallback')
                    ->param('id', $record->id)
            )
        );
    }

    public function selectProjectCallback(): void
    {
        $this->assertCallback();
        $this->makePreparationsForWork();

        $this->assertCurrentCommand('selecting_project', function () {
            $this->deleteKeyboard();
            $this->reply('Sorry, this action is not available at the moment.');
        });

        if (!$this->data->has('reload')) {
            if ($this->callbackQuery->message()->id() != $this->chatContext->state['processCallbackFrom']) {
                $this->deleteKeyboard();
                $this->reply('Sorry, but you can\'t select project from this message.');

                return;
            }
        }

        $this->reply('OK. I will check the project...');

        $this->loading();

        $this->resetProjectRelatedData();

        $this->selectProject($this->data->get('id'));

        $this->deleteKeyboard();

        $this->chat->markdown("Great, project selected 🦊")->removeReplyKeyboard()->send();

        $this->printCurrentInfoAboutProject();

        $this->switchToNextCommandFromCommand('selecting_project');
    }

    protected function printCurrentInfoAboutProject(): void
    {
        $project = $this->resolveProject($this->chatContext->getProjectId());

        $projectDetails = ProjectDetails::makeFromArray($this->chatContext->context_data['projectInfo']);
        $codeInfo = $projectDetails->codeInfo;

        $ciCdOptions = CiCdOptions::makeFromArray(data_get($this->chatContext->context_data, 'ci_cd_options'));
        $templateGroupName = (new CiCdTemplateRepository())->getTemplateGroup($ciCdOptions->template_group)?->nameAndIcon() ?: 'not resolved';

        $code = "Template: *{$templateGroupName}*\n";
        if ($codeInfo->isLaravel) {
            $code .= collect([
                'Laravel' => $codeInfo->frameworkVersion,
                'Admin panel' => $codeInfo->adminPanel,
                'Builder' => $codeInfo->frontendBuilder,
            ])->filter()->map(fn ($value, $name) => "{$name}: *{$value}*")->implode(PHP_EOL);
        } else {
            $code .= collect([
                'Framework' => $codeInfo->frameworkVersion,
                'Builder' => $codeInfo->frontendBuilder,
            ])->filter()->map(fn ($value, $name) => "{$name}: *{$value}*")->implode(PHP_EOL);
        }

        $this->chat->markdown(
            <<<MD
                Project details:
                Name: *{$project->name}*
                Id: *{$project->id}*
                Access level: *{$project->level()->getLabel()}*

                Code:
                {$code}
                MD
        )->send();
    }

    // ------------------------------------
    // Select stage name
    // ------------------------------------

    protected function promptToSelectBranchName(): void
    {
        $branches = rescue(function () {
            return $this->gitLabService->gitLabManager()->repositories()->branches($this->chatContext->getProjectId());
        }, [], report: false);

        $currentBranches = collect($branches)->map(fn ($branch) => $branch['name']);

        $this->chat
            ->message('🍒 Now select stage/branch name to deploy. You can select from buttons or write custom name.')
            ->replyKeyboard(function (ReplyKeyboard $keyboard) use ($currentBranches) {
                $datalist = [
                    'dev',
                    'stage',
                    'master',
                    'prod',
                ];

                foreach ($datalist as $branchName) {
                    $label = $currentBranches->contains($branchName)
                        ? "{$branchName} (exists)"
                        : $branchName;

                    $keyboard->button($label);
                }

                return $keyboard->resize()->oneTime()->chunk(2)->inputPlaceholder('Enter branch name...');
            })
            ->send();

        $this->chat->message("If you choose existing branch, you will be asked to confirm the deployment to this branch.")->send();
    }

    protected function selectingBranch(Stringable $text): void
    {
        if (!data_get($this->chatContext->state, 'branch')) {
            $this->selectBranchName($text);

            return;
        }

        if (data_get($this->chatContext->state, 'branch.need_confirm_to_force')) {
            $this->confirmForceBranchUpdate($text);

            return;
        }
    }

    protected function selectBranchName(Stringable $name): void
    {
        $branchName = $name->before(' ');

        $this->chat->markdown("You chose *{$branchName}*")->removeReplyKeyboard()->send();

        $branches = rescue(function () {
            return $this->gitLabService->gitLabManager()->repositories()->branches($this->chatContext->getProjectId());
        }, [], report: false);

        $currentBranches = collect($branches)->map(fn ($branch) => $branch['name']);

        $isBranchExists = $currentBranches->contains($branchName->value());

        $this->chatContext->pushToState([
            'branch' => [
                'name' => $branchName,
                'need_confirm_to_force' => $isBranchExists,
                'force_update_confirmed' => false,
            ],
        ]);

        if ($isBranchExists) {
            $this->chat->message("This branch exists. When you confirm force update, variables and access keys can be changed.")->send();
            $this->chat->message("Are you confirm to force deploy on this branch?")->replyKeyboard(function (ReplyKeyboard $keyboard) {
                return $keyboard->button('Yes ✅')->button('No ❌')->resize()->oneTime()->chunk(2);
            })->send();

            return;
        }

        $this->switchToNextCommandFromCommand('selecting_branch');
    }

    protected function confirmForceBranchUpdate(Stringable $answer): void
    {
        $branchName = data_get($this->chatContext->state, 'branch.name');

        $answer = $answer->before(' ')->lower();

        if (!$answer->contains(['yes', 'no'])) {
            $this->chat->message("Please select Yes or No.")->send();

            return;
        }

        if ($answer->is('no')) {
            $this->chat->message("Heh, please select another branch.")->removeReplyKeyboard()->send();

            $this->chatContext->pushToState(['branch' => null]);

            $this->executeCommandPrompt('selecting_branch');

            return;
        }

        $this->chat->markdown("I accept your choice. Branch *{$branchName}* can be processed")->removeReplyKeyboard()->send();

        $this->chatContext->pushToState([
            'branch' => [
                'name' => $branchName,
                'need_confirm_to_force' => true,
                'force_update_confirmed' => true,
            ],
        ]);

        $stage = data_get($this->chatContext->context_data, 'stages.0');
        $stage['name'] = $branchName;
        $this->chatContext->pushToData([
            'stages' => [$stage],
        ]);

        $this->switchToNextCommandFromCommand('selecting_branch');
    }

    // ------------------------------------
    // CI/CD settings
    // ------------------------------------

    protected function promptToConfigureCiCdOptions(): void
    {
        $this->printCurrentInfoAboutCiCdOptions();

        $this->chatContext->pushToState([
            'ci_cd_state' => [
                'wait_for_pm2_input' => false,
            ],
        ]);
    }

    protected function processCiCdInput(Stringable $text): void
    {
        $state = $this->chatContext->state['ci_cd_state'];

        if ($state['wait_for_pm2_input']) {
            if ($text->contains('Do not change')) {
                $this->chat->message('Ok, PM2 name will not be changed.')->removeReplyKeyboard()->send();
            } else {
                $this->chat->markdown("Ok, PM2 name will be changed to *{$text}*")->removeReplyKeyboard()->send();

                $newOptions = [
                    'extra' => [
                        'pm2_name' => $text,
                    ],
                ];

                $this->chatContext->pushToData([
                    'ci_cd_options' => array_merge($this->chatContext->context_data['ci_cd_options'], $newOptions),
                ]);
            }

            $this->printCurrentInfoAboutCiCdOptions();

            $state['wait_for_pm2_input'] = false;

            $this->chatContext->pushToState(['ci_cd_state' => $state]);
        }
    }

    protected function canSelectNodeVersion(CiCdOptions $ciCdOptions, ?TemplateInfo $templateInfo): bool
    {
        return ($ciCdOptions->isStageEnabled(CiCdOptions::BuildStage) || $ciCdOptions->template_group === TemplateGroup::Frontend)
            && $templateInfo?->canSelectNodeVersion;
    }

    protected function printCurrentInfoAboutCiCdOptions(bool $withButtons = true): TelegraphResponse
    {
        $ciCdOptions = CiCdOptions::makeFromArray(data_get($this->chatContext->context_data, 'ci_cd_options'));

        $message = $this->makeMessageForCiCdOptions($ciCdOptions);

        if ($withButtons) {
            $buttons = [
                $this->makeCallbackButton('Change 🔧', 'configureCiCdOptionsCallback', ['action' => 'show_main_menu_ci_cd']),
            ];

            if ($this->canConfirmCiCdOptions($ciCdOptions)) {
                $buttons[] = $this->makeCallbackButton('Confirm ✅', 'configureCiCdOptionsCallback', ['action' => 'confirm_ci_cd']);
            }
        } else {
            $buttons = [];
        }

        return $this->chat->markdown($message)->keyboard(
            Keyboard::make()->buttons($buttons)->chunk(2)
        )->send();
    }

    protected function canConfirmCiCdOptions(CiCdOptions $ciCdOptions): bool
    {
        $repository = new CiCdTemplateRepository();
        $templateInfo = $repository->getTemplateInfo($ciCdOptions->template_group, $ciCdOptions->template_key);

        if (is_null($templateInfo)) {
            return false;
        }

        if ($templateInfo->usesPM2() && empty($ciCdOptions->extra('pm2_name'))) {
            return false;
        }

        return true;
    }

    protected function makeMessageForCiCdOptions(CiCdOptions $ciCdOptions): string
    {
        $repository = new CiCdTemplateRepository();

        $templateInfo = $repository->getTemplateInfo($ciCdOptions->template_group, $ciCdOptions->template_key);
        $group = $repository->getTemplateGroup($ciCdOptions->template_group);

        $message = "Used CI/CD options:";

        if ($ciCdOptions->template_group) {
            $message .= "\n\nTemplate\n- type: *{$group->nameAndIcon()}*";

            if ($ciCdOptions->template_key && $templateInfo) {
                $message .= "\n- version: *{$templateInfo->name}*";
            }
        }

        if ($templateInfo?->allowToggleStages) {
            $message .= "\n\nStages: ";
            foreach ($ciCdOptions->enabled_stages as $stageName => $status) {
                $message .= "\n- *{$stageName}* - " . ($status ? 'enabled 🟢' : 'disabled 🔴');
            }
        }

        if ($this->canSelectNodeVersion($ciCdOptions, $templateInfo)) {
            $message .= "\n\nBuild stage";
            $message .= "\nNode.js: *{$ciCdOptions->node_version}*";
            if ($templateInfo->group->isFrontend()) {
                $message .= "\n- using: *{$templateInfo->preferredBuildType()}*";

                if ($templateInfo->usesPM2()) {
                    $message .= "\n- PM2 name: *{$ciCdOptions->extra('pm2_name')}*";
                } else {
                    $message .= "\n- build folder: *{$ciCdOptions->build_folder}*";
                }
            }
        }

        return $message;
    }

    protected function renderMainCiCdMenu(?int $editMessageId = null): Telegraph
    {
        $ciCdOptions = CiCdOptions::makeFromArray(data_get($this->chatContext->context_data, 'ci_cd_options'));

        $templateInfo = (new CiCdTemplateRepository())->getTemplateInfo($ciCdOptions->template_group, $ciCdOptions->template_key);

        $message = 'What do you want to change?';

        $keyboard = function (Keyboard $keyboard) use ($ciCdOptions, $templateInfo) {
            $buttons = [
                $this->makeCallbackButton('Template 📦', 'configureCiCdOptionsCallback', ['action' => 'select_template_group', 'current' => $ciCdOptions->template_group]),
            ];

            if ($templateInfo?->allowToggleStages) {
                $buttons[] = $this->makeCallbackButton('Stages 🧩', 'configureCiCdOptionsCallback', [
                    'action' => 'change_stages',
                ]);
            }

            if ($this->canSelectNodeVersion($ciCdOptions, $templateInfo)) {
                $buttons[] = $this->makeCallbackButton('Node.js 🐢', 'configureCiCdOptionsCallback', [
                    'action' => 'change_node',
                    'current' => $ciCdOptions->node_version,
                ]);
            }

            if ($templateInfo?->usesPM2()) {
                $buttons[] = $this->makeCallbackButton('PM2 name 🚀', 'configureCiCdOptionsCallback', [
                    'action' => 'change_pm2_name',
                    'current' => $ciCdOptions->extra('pm2_name'),
                ]);
            }

            if ($this->canConfirmCiCdOptions($ciCdOptions)) {
                $buttons[] = $this->makeCallbackButton('Confirm ✅', 'configureCiCdOptionsCallback', [
                    'action' => 'confirm_ci_cd',
                ]);
            }

            return $keyboard->buttons($buttons)->chunk(2);
        };

        if ($editMessageId) {
            return $this->chat->edit($editMessageId)->message($message)->keyboard($keyboard);
        }

        return $this->chat->message($message)->keyboard($keyboard);
    }

    public function configureCiCdOptionsCallback(): void
    {
        $this->assertCallback();
        $this->makePreparationsForWork();

        $this->assertCurrentCommand('selecting_ci_cd_options', function () {
            $this->deleteKeyboard();
        });

        /** @var CallbackButton $callbackButton */
        $callbackButton = $this->chatContext->callbackButtons()->where('id', $this->data->get('c_id'))->firstOr(fn () => throw new Halt());

        $backButton = $this->makeCallbackButton("« Back to menu", 'configureCiCdOptionsCallback', ['action' => 'back_to_main_menu_ci_cd']);

        (match ($callbackButton->payload->get('action')) {
            // / ----------------------
            'confirm_ci_cd' => function () {
                $this->reply('CI/CD options saved ✅');

                $this->chat->deleteMessage($this->messageId)->send();
                if ($mid = $this->chatContext->state->get('ci_cd_message_to_edit')) {
                    $this->chat->deleteMessage($mid)->send();
                }

                $this->chat->message('Here is your CI/CD configuration:')->send();

                $this->printCurrentInfoAboutCiCdOptions(withButtons: false);

                $this->chatContext->fill([
                    'state' => $this->chatContext->state->forget('ci_cd_message_to_edit'),
                ]);

                $this->switchToNextCommandFromCommand('selecting_ci_cd_options');
            },
            // / ----------------------
            'show_main_menu_ci_cd' => function () {
                $this->deleteKeyboard();

                $this->chatContext->pushToState(['ci_cd_message_to_edit' => $this->messageId]);

                $this->renderMainCiCdMenu()->send();
            },
            'back_to_main_menu_ci_cd' => function () {
                $this->renderMainCiCdMenu($this->messageId)->send();
                $this->chatContext->pushToState([
                    'ci_cd_state' => [
                        'wait_for_pm2_input' => false,
                        'wait_for_build_folder_input' => false,
                    ],
                ]);
            },
            // / ----------------------
            'select_template_group' => function () use ($callbackButton, $backButton) {
                $currentGroup = $callbackButton->payload->get('current');

                $this->chat->edit($this->messageId)->markdown('Select template for your project: ')->keyboard(function (Keyboard $keyboard) use ($currentGroup, $backButton) {
                    $buttons = collect((new CiCdTemplateRepository())->getTemplateGroups())
                        ->when(!$this->user->isRoot() && !empty($currentGroup), function (Collection $groups) use ($currentGroup) {
                            return $groups->only($currentGroup);
                        })
                        ->map(function (TemplateGroup $group) {
                            return $this->makeCallbackButton($group->nameAndIcon(), 'configureCiCdOptionsCallback', [
                                'action' => 'select_template_version',
                                'group' => $group->key,
                            ]);
                        })
                        ->values();

                    $buttons->push($backButton);

                    $keyboard->buttons($buttons);

                    return $keyboard;
                })->send();

                $this->refreshCiCdMessage();
            },
            'select_template_version' => function () use ($callbackButton) {
                $selectGroup = $callbackButton->payload->get('group');
                if (!$this->isFrontendProjectsAllowed() && $selectGroup == TemplateGroup::Frontend) {
                    $this->reply('Unfortunately, frontend templates are not supported yet from telegram bot.');

                    return;
                }

                $this->chat->edit($this->messageId)->markdown('Select template version: ')->keyboard(function (Keyboard $keyboard) use ($selectGroup) {
                    $backButton = $this->makeCallbackButton("« Back to templates", 'configureCiCdOptionsCallback', ['action' => 'select_template_group']);

                    $buttons = collect((new CiCdTemplateRepository())->getTemplatesForGroup($selectGroup))
                        ->map(function (TemplateInfo $templateInfo) use ($selectGroup) {
                            $icon = $templateInfo->isDisabled ? '✋' : '👀';

                            return $this->makeCallbackButton("{$templateInfo->name} {$icon}", 'configureCiCdOptionsCallback', [
                                'action' => 'save_selected_template',
                                'group' => $selectGroup,
                                'template' => $templateInfo->key,
                            ]);
                        })
                        ->filter()
                        ->values();

                    $buttons->push($backButton);

                    $keyboard->buttons($buttons);

                    return $keyboard;
                })->send();

                $this->refreshCiCdMessage();
            },
            'save_selected_template' => function () use ($callbackButton) {
                $newOptions = [
                    'template_group' => $group = $callbackButton->payload->get('group'),
                    'template_key' => $template = $callbackButton->payload->get('template'),
                ];

                $templateInfo = (new CiCdTemplateRepository())->getTemplateInfo($group, $template);

                if (is_null($templateInfo) || $templateInfo->isDisabled) {
                    $this->reply('Unfortunately, this template is disabled and not yet supported. Choose another.');

                    return;
                }

                $this->chatContext->pushToData([
                    'ci_cd_options' => array_merge($this->chatContext->context_data['ci_cd_options'], $newOptions),
                ]);

                $this->reply('Template changed ✅');

                $this->renderMainCiCdMenu($this->messageId)->send();

                $this->refreshCiCdMessage();
            },
            // / ----------------------
            'change_stages', 'save_stage_status' => function () use ($callbackButton, $backButton) {
                $enabledStages = data_get($this->chatContext->context_data, 'ci_cd_options.enabled_stages');

                /** @var string|null $stageName */
                $stageName = $callbackButton->payload->get('stage_name');
                if ($stageName) {
                    if ($stageName == CiCdOptions::DeployStage) {
                        $this->reply('You can not disable deploy stage)');
                    } else {
                        $enabledStages[$stageName] = $callbackButton->payload->get('new_status');

                        $newOptions = [
                            'enabled_stages' => $enabledStages,
                        ];

                        $this->chatContext->pushToData([
                            'ci_cd_options' => array_merge($this->chatContext->context_data['ci_cd_options'], $newOptions),
                        ]);
                    }
                }

                $this->chat->edit($this->messageId)->markdown('Which stage do you want to toggle?')->keyboard(function (Keyboard $keyboard) use ($backButton, $enabledStages) {
                    $buttons = collect($enabledStages)
                        ->map(function ($status, $stageName) {
                            $icon = $status ? '🟢 > 🔴' : '🔴 > 🟢';
                            if ($stageName == CiCdOptions::DeployStage) {
                                $icon = '⚪';
                            }

                            return $this->makeCallbackButton("{$stageName}: {$icon}", 'configureCiCdOptionsCallback', [
                                'action' => 'save_stage_status',
                                'stage_name' => $stageName,
                                'new_status' => !$status,
                            ]);
                        })
                        ->values();

                    $buttons->push($backButton);

                    $keyboard->buttons($buttons);

                    return $keyboard;
                })->send();

                $this->refreshCiCdMessage();
            },
            // / ----------------------
            'change_pm2_name' => function () use ($callbackButton) {
                $this->chatContext->pushToState([
                    'ci_cd_state' => [
                        'wait_for_pm2_input' => true,
                    ],
                ]);

                $this->chat->deleteMessage($this->messageId)->send();

                $this->chat->message('Send me new PM2 name:')->replyKeyboard(function (ReplyKeyboard $keyboard) use ($callbackButton) {
                    if ($callbackButton->payload['current']) {
                        $keyboard->button('Do not change');
                    }

                    $project = $this->resolveProject($this->chatContext->getProjectId());

                    $suggestName = str($project->name)->after('.')->beforeLast('.')->lower()->snake()->value();

                    return $keyboard->button($suggestName)->chunk(2)->resize()->oneTime()->inputPlaceholder('Choose option...');
                })->send();
            },
            // / ----------------------
            'change_node' => function () use ($backButton) {
                $this->chat->edit($this->callbackQuery->message()->id())->message('Change node version to:')->keyboard(function (Keyboard $keyboard) use ($backButton) {
                    $buttons = collect(CiCdOptions::getNodeVersions())->values()
                        ->map(fn (string $v) => $this->makeCallbackButton($v, 'configureCiCdOptionsCallback', [
                            'action' => 'save_node_version',
                            'v' => $v,
                        ]));

                    $buttons->push($backButton);

                    $keyboard->buttons($buttons);

                    return $keyboard;
                })->send();
            },
            'save_node_version' => function () use ($callbackButton) {
                $newOptions = [
                    'node_version' => $callbackButton->payload->get('v'),
                ];

                $this->chatContext->pushToData([
                    'ci_cd_options' => array_merge($this->chatContext->context_data['ci_cd_options'], $newOptions),
                ]);

                $this->renderMainCiCdMenu($this->messageId)->send();

                $this->refreshCiCdMessage();
            },
            default => function () {
                $this->reply('Invalid callback button');
            },
        })();
    }

    protected function refreshCiCdMessage(): void
    {
        if ($mid = $this->chatContext->state->get('ci_cd_message_to_edit')) {
            $ciCdOptions = CiCdOptions::makeFromArray(data_get($this->chatContext->context_data, 'ci_cd_options'));
            $this->chat->edit($mid)->markdown($this->makeMessageForCiCdOptions($ciCdOptions))->send();
        }
    }

    // ------------------------------------
    // Access parsing
    // ------------------------------------

    protected function promptForAccessInput(): void
    {
        $stage = data_get($this->chatContext->context_data, 'stages.0');

        $this->chat->markdown(
            "🛡 Now to continue, send me access data for *{$stage['name']}* server in the following format: "
            . "\n*Split each access group only by one line and without empty lines between*."
            . "\n\nFor example:"
        )->send();

        $sampleInput = (new SampleFormData())->getSampleInput();

        $this->chat->message($sampleInput)->send();

        $this->resetAccessParseState();
    }

    protected function resetAccessParseState(): void
    {
        $this->chatContext->pushToState([
            'parse_state' => [
                //
                'wait_for_access_input' => true,
                'can_be_parsed' => false,
                'parsed' => false,
                //
                'wait_for_confirm' => true,
                'access_is_correct' => false,
                //
                'ssh_connected' => false,
                'server_connection_result' => null,
            ],
        ]);
    }

    protected function processAccessParsingInput(Stringable $text): void
    {
        $stage = data_get($this->chatContext->context_data, 'stages.0');
        $parseState = $this->chatContext->state['parse_state'];

        if ($parseState['wait_for_access_input']) {
            $this->parseAccessDataInput($text);

            return;
        }

        if ($parseState['wait_for_confirm']) {
            $answer = $text->before(' ')->lower();

            if ($answer->is('no')) {
                $this->chat->message('Hmm... Ok, send me a new access data...')->removeReplyKeyboard()->send();

                $parseState['wait_for_access_input'] = true;
                $this->chatContext->pushToState(['parse_state' => $parseState]);

                return;
            }

            if (!$answer->is('yes')) {
                $this->reply('Invalid option');

                $parseState['wait_for_access_input'] = true;
                $this->chatContext->pushToState(['parse_state' => $parseState]);

                return;
            }

            $parseState['wait_for_confirm'] = false;
            $parseState['access_is_correct'] = true;

            $this->chat->message('Great! Now I will try to connect to the server...')->removeReplyKeyboard()->send();
            $this->loading();

            $parseState['server_connection_result'] = null;
            $parseState['ssh_connected'] = false;

            $isTest = data_get($this->chatContext->context_data, 'projectInfo.is_test');

            $serverDetailParser = new ServerDetailParser(
                $server = new Server(data_get($this->chatContext->context_data, 'accessInfo.server')),
                $sshOptions = SshOptions::makeFromArray(data_get($this->chatContext->context_data, 'options.ssh') ?: []),
            );

            try {
                $serverDetailParser->establishSSHConnection();

                $this->chat->message("Server connection result: ✅")->send();
            } catch (DomainException $exception) {
                $this->chat->message("Server connection result: ❌")->send();

                $this->chat->markdown("I can't connect to the server. Please check the access data and try again.\n\n```\n{$exception->getMessage()}\n```")->send();
                if ($sshOptions->useCustomSshKey || empty($server->password)) {
                    $this->chat->message('⚠ If you using private key to connect, use web version. Authorization with private key is not supported in telegram.')->send();
                }

                $this->chatContext->pushToState(['parse_state' => $parseState]);
                $this->chatContext->pushToData(['stages' => [$stage]]);

                return;
            }

            $parseState['ssh_connected'] = true;

            $this->chat->message('Fetching server information...')->send();

            $this->loading();

            $templateGroup = (new CiCdTemplateRepository())->getTemplateGroup(data_get($this->chatContext->context_data, 'ci_cd_options.template_group'));
            $isBackend = (is_null($templateGroup) || $templateGroup->isBackend());

            $serverDetailParser->setIsBackendServer($isBackend);
            $serverDetailParser->parse();

            $parseResult = $serverDetailParser->getParseResult();

            $testFolder = $isTest ? '/test' : '';

            // set server details options
            $domain = str($server->domain)->after('://')->value();
            $baseDir = in_array($stage['name'], ['dev', 'stage'])
                ? "{$parseResult['homeFolderPath']}/web/{$domain}/public_html"
                : "{$parseResult['homeFolderPath']}/{$domain}/www";

            $newOptions = [
                'base-dir-pattern' => $baseDir . $testFolder,
                'home-folder' => $parseResult['homeFolderPath'] . $testFolder,
                ...($isBackend ? [
                    'bin-php' => $parseResult['phpInfo']['bin'],
                    'bin-composer' => "{$parseResult['phpInfo']['bin']} " . collect($parseResult['paths'])->get('composer')['bin'],
                ] : [
                    'bin-php' => null,
                    'bin-composer' => null,
                ]),
            ];
            $stage['options'] = array_merge($stage['options'], $newOptions);

            $info = collect([
                "deploy folder: `{$baseDir}`",
                "home folder: `{$parseResult['homeFolderPath']}`",
                ...($isBackend ? [
                    "--------",
                    "*bin paths*",
                    ...collect($parseResult['paths'])->map(fn ($path, $type) => "{$type}: `{$path['bin']}`"),
                    "--------",
                    "all php bins: `{$parseResult['phpInfo']['all']}`",
                    "--------",
                    "*php: ({$parseResult['phpVersion']})*",
                    "```\n{$parseResult['phpOutput']}```",
                    "--------",
                    "*composer: ({$parseResult['composerVersion']})*",
                    "```\n{$parseResult['composerOutput']}```",
                    "--------",
                ] : []),
            ])->implode("\n");
            $parseState['server_connection_result'] = $info;

            $telegraphResponse = $this->chat->markdown("Server information:\n\n{$info}")->send();

            $this->chatContext->pushToState(['parse_state' => $parseState]);
            $this->chatContext->pushToData(['stages' => [$stage]]);

            $this->switchToNextCommandFromCommand('access_parsing');
        }
    }

    protected function parseAccessDataInput(Stringable $text): void
    {
        $stage = data_get($this->chatContext->context_data, 'stages.0');
        $parseState = $this->chatContext->state['parse_state'];

        $this->resetAccessParseState();

        $this->reply('Access data accepted. I start processing...');

        $this->loading();

        $stageName = $stage['name'];

        /** @var DeployConfigBuilder|null $parser */
        $parser = rescue(
            callback: fn () => resolve(DeployConfigBuilder::class)->parseInputForAccessPayload($stageName, $text),
            rescue: fn (Throwable $e) => $this->reply("Invalid content!\n\n" . $e->getMessage())
        );

        if (is_null($parser)) {
            throw new Halt();
        }

        $parseState['wait_for_access_input'] = false;

        $stage['access_input'] = $text->value();

        $parser->setStagesList([$stage]);
        $parser->setProjectDetails(ProjectDetails::makeFromArray($this->chatContext->context_data['projectInfo']));

        $parseState['can_be_parsed'] = true;
        $parseState['parsed'] = true;

        $stage = $parser->processStages($stageName)[0];

        $this->chatContext->pushToData([
            'accessInfo' => $accessInfo = $parser->getAccessInfo($stageName),
        ]);

        $this->chat->message('Access data processed 🧾')->send();

        $message = '';
        foreach ($accessInfo as $type => $info) {
            $title = match ($type) {
                'server' => '🖥 Server',
                'database' => '🐬 MySQL',
                'mail' => '📨 SMTP',
            };

            $message .= "\n\n*{$title}*";
            foreach ($info as $key => $value) {
                $message .= "\n{$key}: `{$value}`";
            }
        }

        $this->chat->markdown($message)->send();

        $this->chat
            ->markdown(
                "Are you confirm the parsed access data?\n\n" .
                "If yes, I will try to connect and fetch server details. Otherwise, you can send me new access data."
            )
            ->replyKeyboard(function (ReplyKeyboard $keyboard) {
                return $keyboard->button('Yes ✅')->button('No 🔁')->chunk(2)->resize()->oneTime()->inputPlaceholder('Choose option...');
            })->send();

        $notResolved = $parser->getNotResolved($stageName);
        if (!empty($notResolved)) {
            $message = "⚠ You have some unresolved sections in your access data. Please review the data.\n";

            foreach ($notResolved as $info) {
                $message .= "\n*Section #{$info['chunk']}*\n";
                $message .= collect($info['lines'])->map(fn ($line) => "- {$line}")->implode(PHP_EOL);
            }

            $this->chat->markdown($message)->send();
        }

        $this->chatContext->pushToState(['parse_state' => $parseState]);
        $this->chatContext->pushToData(['stages' => [$stage]]);
    }

    protected function connectToServer(array $server, ?array $sshOptions = []): bool|SSH2
    {
        $ssh = new SSH2($server['host'], $server['port'] ?? 22);

        if (data_get($sshOptions, 'use_custom_ssh_key', false)) {
            $privateKey = data_get($sshOptions, 'private_key');
            if (empty($privateKey)) {
                $this->chat->message('❗ Private key is empty')->send();

                return false;
            }

            $key = PublicKeyLoader::load(
                key: $privateKey,
                password: data_get($sshOptions, 'private_key_password') ?: false
            );
        } else {
            $key = data_get($server, 'password');
        }

        if ($ssh->login($server['login'], $key)) {
            return $ssh;
        }

        return false;
    }

    // ------------------------------------
    // Deployment settings
    // ------------------------------------

    protected function promptForDeploymentSettings(): void
    {
        $this->chat->message('Now, let\'s configure deployment settings.')->send();

        $this->printCurrentInfoAboutDeploymentSettings();
    }

    protected function printCurrentInfoAboutDeploymentSettings(bool $withButtons = true): TelegraphResponse
    {
        $options = StageOptions::makeFromArray(data_get($this->chatContext->context_data, 'stages.0.options'));

        $message = $this->makeMessageForDeploymentSettings($options);

        return $this->chat->markdown($message)->keyboard(
            Keyboard::make()->buttons(
                $withButtons ? [
                    $this->makeCallbackButton('Change 🔧', 'configureDeploymentSettingsCallback', ['action' => 'show_main_menu_deployment_settings']),
                    $this->makeCallbackButton('Confirm ✅', 'configureDeploymentSettingsCallback', ['action' => 'confirm_deployment_settings']),
                ] : []
            )->chunk(2)
        )->send();
    }

    protected function makeMessageForDeploymentSettings(StageOptions $options): string
    {
        $templateGroup = (new CiCdTemplateRepository())->getTemplateGroup(data_get($this->chatContext->context_data, 'ci_cd_options.template_group'));
        $isBackend = (is_null($templateGroup) || $templateGroup->isBackend());

        $newOptions = [
            'base-dir-pattern' => $options->baseDirPattern,
            'home-folder' => $options->homeFolder,
            'bin-php' => $options->binPhp,
            'bin-composer' => $options->binComposer,
        ];

        $message = "🧰 Options for deployment:\n";
        foreach ($newOptions as $name => $value) {
            if (!$isBackend && in_array($name, ['bin-php', 'bin-composer'])) {
                continue;
            }

            $title = [
                'base-dir-pattern' => 'Deploy folder',
                'home-folder' => 'Home folder',
                'bin-php' => 'bin/php',
                'bin-composer' => 'bin/composer',
            ];
            $message .= "\n- *{$title[$name]}*: `{$value}`";
        }

        return $message;
    }

    protected function renderMainDeploymentSettingsMenu(?int $editMessageId = null): Telegraph
    {
        $message = 'What do you want to change?';

        $keyboard = function (Keyboard $keyboard) {
            $buttons = [
                $this->makeCallbackButton('Server paths (home, bins)', 'configureDeploymentSettingsCallback', ['action' => 'server_paths']),
                // todo - bash aliases
                // $this->makeCallbackButton('Other options', 'configureDeploymentSettingsCallback', ['action' => 'other_options']),
            ];

            $buttons[] = $this->makeCallbackButton('Nothing, continue ✅', 'configureDeploymentSettingsCallback', [
                'action' => 'confirm_deployment_settings',
            ]);

            return $keyboard->buttons($buttons);
        };

        if ($editMessageId) {
            return $this->chat->edit($editMessageId)->message($message)->keyboard($keyboard);
        }

        return $this->chat->message($message)->keyboard($keyboard);
    }

    protected function processDeploymentSettingsInput(Stringable $text): void
    {
        $state = data_get($this->chatContext->state, 'deploy_settings_state') ?: [];

        if (empty($state) || !$state['wait_for_input'] ?? false) {
            throw new Halt();
        }

        $stage = data_get($this->chatContext->context_data, 'stages.0');
        $options = data_get($stage, 'options');

        $property = $state['property'];

        $text = $text->after('\\');

        if ($text->lower()->is('do not change')) {
            $this->chat->message('Property not changed')->removeReplyKeyboard()->send();
        } else {
            $options[$property] = $text->value();
            $this->chat->markdown("Property *{$property}* changed to `{$text}`")->removeReplyKeyboard()->send();
        }

        $stage['options'] = $options;

        $this->chatContext->pushToData([
            'stages' => [$stage],
        ]);

        $state['wait_for_input'] = false;
        $this->chatContext->pushToState([
            'deploy_settings_state' => $state,
        ]);

        $this->printCurrentInfoAboutDeploymentSettings(withButtons: false);
        $this->renderMainDeploymentSettingsMenu()->send();
    }

    public function configureDeploymentSettingsCallback(): void
    {
        $this->assertCallback();
        $this->makePreparationsForWork();

        $this->assertCurrentCommand('deployment_settings', function () {
            $this->deleteKeyboard();
        });

        /** @var CallbackButton $callbackButton */
        $callbackButton = $this->chatContext->callbackButtons()->where('id', $this->data->get('c_id'))->firstOr(fn () => throw new Halt());

        $backButton = $this->makeCallbackButton("« Back to menu", 'configureDeploymentSettingsCallback', ['action' => 'back_to_main_menu_deployment_settings']);

        (match ($callbackButton->payload->get('action')) {
            // / ----------------------
            'confirm_deployment_settings' => function () {
                $this->reply('Deployment settings saved ✅');

                $this->chat->deleteMessage($this->messageId)->send();
                if ($mid = $this->chatContext->state->get('deployment_settings_message_to_edit')) {
                    $this->chat->deleteMessage($mid)->send();
                }

                $this->chat->message('Here is your deployment settings:')->send();

                $this->printCurrentInfoAboutDeploymentSettings(withButtons: false);

                $this->chatContext->fill([
                    'state' => $this->chatContext->state->forget('deployment_settings_message_to_edit'),
                ]);

                $this->switchToNextCommandFromCommand('deployment_settings');
            },
            // / ----------------------
            'show_main_menu_deployment_settings' => function () {
                $this->deleteKeyboard();

                $this->chatContext->pushToState(['deployment_settings_message_to_edit' => $this->messageId]);

                $this->renderMainDeploymentSettingsMenu()->send();
            },
            'back_to_main_menu_deployment_settings' => function () {
                $this->renderMainDeploymentSettingsMenu($this->messageId)->send();
            },
            // / ----------------------
            'server_paths' => function () use ($backButton) {
                $this->chat->edit($this->messageId)->markdown('Change server paths:')->keyboard(function (Keyboard $keyboard) use ($backButton) {
                    $templateGroup = (new CiCdTemplateRepository())->getTemplateGroup(data_get($this->chatContext->context_data, 'ci_cd_options.template_group'));
                    $isBackend = (is_null($templateGroup) || $templateGroup->isBackend());

                    $buttons = [
                        $this->makeCallbackButton('Deploy folder', 'configureDeploymentSettingsCallback', ['action' => 'edit_path', 'property' => 'base-dir-pattern']),
                        $this->makeCallbackButton('Home folder', 'configureDeploymentSettingsCallback', ['action' => 'edit_path', 'property' => 'home-folder']),
                    ];

                    if ($isBackend) {
                        $buttons = array_merge($buttons, [
                            $this->makeCallbackButton('bin/php', 'configureDeploymentSettingsCallback', ['action' => 'edit_path', 'property' => 'bin-php']),
                            $this->makeCallbackButton('bin/composer', 'configureDeploymentSettingsCallback', ['action' => 'edit_path', 'property' => 'bin-composer']),
                        ]);
                    }

                    $buttons[] = $backButton;

                    $keyboard->buttons($buttons);

                    return $keyboard;
                })->send();
            },
            'edit_path' => function () use ($callbackButton) {
                $message = match ($callbackButton->payload->get('property')) {
                    'base-dir-pattern' => 'Change deploy folder path',
                    'home-folder' => 'Change home folder path',
                    'bin-php' => 'Change php bin path',
                    'bin-composer' => 'Change composer bin path',
                    default => throw new Halt(),
                };

                $this->chatContext->pushToState([
                    'deploy_settings_state' => [
                        'wait_for_input' => true,
                        'action' => 'edit_path',
                        'property' => $callbackButton->payload->get('property'),
                    ],
                ]);

                $this->chat
                    ->message("{$message}. \nEnter new value or press 'do not change' button to skip editing.\n\nPlease, prepend backslashes on start '\\' to avoid command trigger.")
                    ->replyKeyboard(function (ReplyKeyboard $keyboard) {
                        return $keyboard->button('Do not change')->resize()->oneTime();
                    })->send();
                $this->chat->deleteMessage($this->messageId)->send();
            },
            default => function () {
                $this->reply('Invalid callback button');
            },
        })();
    }

    protected function refreshDeploymentOptionsMessage(): void
    {
        if ($mid = $this->chatContext->state->get('deployment_settings_message_to_edit')) {
            $options = StageOptions::makeFromArray(data_get($this->chatContext->context_data, 'stages.0.options'));
            $this->chat->edit($mid)->markdown($this->makeMessageForDeploymentSettings($options))->send();
        }
    }

    // ------------------------------------
    // Confirmation
    // ------------------------------------

    protected function promptForConfirmation(): void
    {
        $this->chat->message('📌 Please confirm your project details:')->send();

        $this->printCurrentInfoAboutProject();
        $this->printCurrentInfoAboutCiCdOptions(withButtons: false);
        $this->printCurrentInfoAboutDeploymentSettings(withButtons: false);

        $this->chat->message('Press "Confirm" to start deployment.')->keyboard(
            Keyboard::make()->buttons([
                $this->makeCallbackButton('Confirm ✅', 'handleConfirmationCallback', ['action' => 'setupRepository']),
                // $this->makeCallbackButton('Change project details', 'configureProjectCallback', ['action' => 'show_main_menu_project']),
                // $this->makeCallbackButton('Change CI/CD options', 'configureCiCdOptionsCallback', ['action' => 'show_main_menu_ci_cd']),
                // $this->makeCallbackButton('Change deployment settings', 'configureDeploymentSettingsCallback', ['action' => 'show_main_menu_deployment_settings']),
            ])->chunk(2)
        )->send();
    }

    public function handleConfirmationCallback(): void
    {
        $this->assertCallback();
        $this->makePreparationsForWork();

        $this->assertCurrentCommand('confirmation', function () {
            $this->deleteKeyboard();
        });

        /** @var CallbackButton $callbackButton */
        $callbackButton = $this->chatContext->callbackButtons()->where('id', $this->data->get('c_id'))->firstOr(fn () => throw new Halt());

        if ($callbackButton->payload->get('action') === 'setupRepository') {
            $this->chat->deleteMessage($this->messageId)->send();

            $this->loading();

            $this->startToSetupRepository();

            $this->chat->message('🎉')->send();

            $this->chat->message('Your data saved and project will be deployed soon.')->send();

            $this->finish();
        }
    }

    protected function startToSetupRepository(): void
    {
        $state = $this->chatContext->state;
        $data = $this->chatContext->context_data;

        $projectDetails = ProjectDetails::makeFromArray($data['projectInfo']);
        $ciCdOptions = CiCdOptions::makeFromArray($data['ci_cd_options']);

        $deployProject = DeployProjectBuilder::make($projectDetails)
            ->user($this->user)
            ->openedAt($state['openedAt'])
            ->ciCdOptions($ciCdOptions)
            ->stages($data['stages'])
            ->create('telegram-bot');

        dispatch(new ConfigureRepositoryJob(userId: $this->user->getAuthIdentifier(), deployProject: $deployProject));
    }

    protected function finish(): void
    {
        $this->resetChatContext();
    }
}
