@php
    /**
     * @var array $stages
     * @var array $stageConfig
     * @var bool $isBackend
     * @var \App\Models\User $user
     * @var \App\Domains\DeployConfigurator\Data\TemplateInfo $templateInfo
     * @var \App\Domains\DeployConfigurator\Data\ProjectDetails $projectDetails
     * @var \App\Domains\DeployConfigurator\Data\CiCdOptions $ciCdOptions
     */

    $copiableClasses = 'copiable bg-gray-100 dark:bg-gray-700';
@endphp

<div x-data="{
            copy(event) {
                window.navigator.clipboard.writeText(event.target.innerText);
                event.target.classList.add('done');
                setTimeout(() => {
                    event.target.classList.remove('done');
                }, 1000);
                new FilamentNotification()
                    .title('Copied!')
                    .success()
                    .send()
            }
        }">
    <style>
        .copiable {
            cursor: pointer;
            padding: 0 0.4rem;
            border-radius: 0.40rem;
            transition: background-color 0.3s ease-in-out, border-color 0.3s ease-in-out;
            border: 1px solid #e5e7eb;
        }

        .copiable:hover {
            background-color: rgba(var(--gray-200));
        }

        .copiable:hover:is(.dark *):not(*.done) {
            background-color: rgba(var(--gray-500));
        }

        .copiable.done {
            background-color: rgba(var(--primary-200));
            border: 1px solid rgba(var(--primary-500));
        }
    </style>


    @foreach($stages as $stageConfig)
        @php
            $serverConfig = $stageConfig['server'];

            $DEPLOY_BASE_DIR = \Illuminate\Support\Str::replace(
                        ['{{HOST}}', '{{USER}}'],
                        [$serverConfig['host'], $serverConfig['login']],
                        $stageConfig['options']['base-dir-pattern']
                    );
            $BIN_PHP = $stageConfig['options']['bin-php'];
        @endphp

        <div class="mb-4">
            <div class="">
                <h2 class="text-2xl text-center font-bold">{{$stageConfig['name']}}</h2>
            </div>

            <div class="c-section mt-6 mb-2 p-3 rounded-lg border shadow-lg space-y-2">
                <p class="c-heading font-bold">Links:</p>
                @php
                    $links = [
                        [ 'name' => 'Website', 'url' => $serverConfig['domain'] ],
                        [ 'name' => 'Repository', 'url' => $projectUrl ],
                        [ 'name' => 'Pipelines', 'url' => "{$projectUrl}/-/pipelines" ],
                        [ 'name' => 'Commits', 'url' => "{$projectUrl}/-/commits/{$stageConfig['name']}" ],
                    ];
                @endphp
                <div class="c-content space-y-2 spa">
                    @foreach($links as $link)
                        <p>
                            {{$link['name']}}:
                            {{--<span class="{{$copiableClasses}}" @click="copy($event)">{{$link['url']}}</span>--}}
                            <x-filament::button
                                tag="a" size="xs" style="margin-left: 1rem;" href="{{$link['url']}}"
                                icon="heroicon-s-arrow-top-right-on-square" icon-position="after"
                                target="_blank"
                                :outlined="true"
                            >
                                Open
                            </x-filament::button>
                        </p>
                    @endforeach
                </div>
            </div>

            <div class="c-section mt-6 mb-2 p-3 rounded-lg border shadow-lg space-y-2">
                <p class="c-heading font-bold">
                    <x-heroicon-o-server-stack class="w-7 h-7 inline-block mr-1"/>
                    Connect to server
                </p>
                <div class="c-content space-y-2">
                    <p>Host: <span class="{{$copiableClasses}}" @click="copy($event)">{{ $serverConfig['host'] }}</span></p>
                    <p>Port: <span class="{{$copiableClasses}}" @click="copy($event)">{{ $serverConfig['ssh-port'] ?? 22 }}</span></p>
                    <p>Login: <span class="{{$copiableClasses}}" @click="copy($event)">{{ $serverConfig['login'] }}</span></p>
                    <p>Password: <span class="{{$copiableClasses}}" @click="copy($event)">{{ $serverConfig['password'] ?: '(ssh key)' }}</span></p>
                </div>

                <div class="c-content space-y-2">
                    <p class="font-bold">Mount path</p>
                    <p><span class="{{$copiableClasses}}" @click="copy($event)">{{$DEPLOY_BASE_DIR}}</span></p>
                </div>

                <div class="c-content space-y-2">
                    <p class="font-bold">SSH commands</p>
                    <p>
                        Generate ssh key:
                        <span class="{{$copiableClasses}}" @click="copy($event)">
                            mkdir ./.ssh -p && ssh-keygen -t rsa -f "./.ssh/id_rsa" -N "" -C "{{ $user->email }}"
                        </span>
                    </p>
                    <p>
                        Copy to remote:
                        <span class="{{$copiableClasses}}" @click="copy($event)">
                            ssh-copy-id -i .ssh/id_rsa -p {{$serverConfig['ssh-port'] ?? 22}} {{ $serverConfig['login'] . '@' . $serverConfig['host']}}
                        </span>
                    </p>
                    <p>
                        Connect from console:
                        <span class="{{$copiableClasses}}" @click="copy($event)">
                            ssh -i .ssh/id_rsa -p {{$serverConfig['ssh-port'] ?? 22}} {{ $serverConfig['login'] . '@' . $serverConfig['host']}}
                        </span>
                    </p>
                </div>

                @if($templateInfo->preferredBuildFolder())
                    <div class="">
                        <p class="font-bold">Add mapping for deployment</p>
                        <p>
                            <span class="{{$copiableClasses}}" @click="copy($event)">/{{ $ciCdOptions->build_folder }}</span> -> /
                        </p>
                    </div>
                @endif
            </div>

            @if($isBackend)
                <div class="c-section mt-6 mb-2 p-3 rounded-lg border shadow-lg space-y-2">
                    <p class="c-heading font-bold">
                        <x-heroicon-o-circle-stack class="w-7 h-7 inline-block mr-1"/>
                        Connect to database
                    </p>
                    <div class="c-content space-y-2">
                        <p>Database: <span class="{{$copiableClasses}}" @click="copy($event)">{{ $stageConfig['database']['database'] }}</span></p>
                        <p>User: <span class="{{$copiableClasses}}" @click="copy($event)">{{ $stageConfig['database']['username'] }}</span></p>
                        <p>Password: <span class="{{$copiableClasses}}" @click="copy($event)">{{ $stageConfig['database']['password'] }}</span></p>
                    </div>
                </div>

                <div class="c-section mt-6 mb-2 p-3 rounded-lg border shadow-lg space-y-2">
                    <p class="c-heading font-bold">
                        <x-heroicon-o-clock class="w-7 h-7 inline-block mr-1"/>
                        Configure crontab / scheduler
                    </p>
                    <div class="c-content space-y-2">
                        <p class="italic">To open crontab editor, execute:</p>
                        <p><span class="{{$copiableClasses}}" @click="copy($event)">crontab -e</span></p>
                        <p class="italic">and write:</p>
                        <p class="{{$copiableClasses}}" @click="copy($event)">
                            * * * * * cd {{$DEPLOY_BASE_DIR}}/current && {{$BIN_PHP}} artisan schedule:run >> /dev/null 2>&1
                        </p>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
