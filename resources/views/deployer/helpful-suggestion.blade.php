@php
    /**
     * @var array $stages
     * @var array $stageConfig
     * @var bool $isBackend
     * @var \App\Domains\DeployConfigurator\Data\ProjectDetails $projectDetails
     * @var \App\Domains\DeployConfigurator\Data\CiCdOptions $ciCdOptions
     */
@endphp

<div class="">
    <style>
        .copiable {
            cursor: pointer;
            padding: 0 0.4rem;
            background-color: #f3f4f6;
            border-radius: 0.40rem;
            transition: background-color 0.3s ease-in-out;
            border: 1px solid #e5e7eb;
        }

        .copiable:hover {
            background-color: #e5e7eb;
        }

        .copiable.done {
            background-color: #fff1b8;
            border: 1px solid #f7cb15;
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

        <div class="mb-4" x-data="{
            copy(event) {
                window.navigator.clipboard.writeText(event.target.innerText);
                event.target.classList.add('done');
                setTimeout(() => {
                    event.target.classList.remove('done');
                }, 1000);
            }
        }">
            <div class="">
                <h2 class="text-2xl text-center font-bold">{{$stageConfig['name']}}</h2>
            </div>

            <div class="">
                <p>
                    <span class="font-bold">Site url</span>
                    <a class="ml-2" href="{{ $serverConfig['domain'] }}" target="_blank">
                        Open
                        <x-heroicon-s-arrow-top-right-on-square class="w-4 h-4 inline-block"/>
                    </a>
                </p>
                <p><span class="copiable" @click="copy($event)">{{ $serverConfig['domain'] }}</span></p>
            </div>

            <div class="mt-6 mb-2">
                <p class="font-bold">
                    <x-heroicon-o-server-stack class="w-7 h-7 inline-block mr-1"/>
                    Connect to server
                </p>
                <div class="">
                    <p>Host: <span class="copiable" @click="copy($event)">{{ $serverConfig['host'] }}</span></p>
                    <p>Port: <span class="copiable" @click="copy($event)">{{ $serverConfig['ssh-port'] ?? 22 }}</span></p>
                    <p>Login: <span class="copiable" @click="copy($event)">{{ $serverConfig['login'] }}</span></p>
                    <p>Password: <span class="copiable" @click="copy($event)">{{ $serverConfig['password'] ?: '(ssh key)' }}</span></p>
                </div>

                <div class="">
                    <p class="font-bold">Mount path</p>
                    <p><span class="copiable" @click="copy($event)">{{$DEPLOY_BASE_DIR}}</span></p>
                </div>

                @if($templateInfo->preferredBuildFolder())
                    <div class="">
                        <p class="font-bold">Add mapping for deployment</p>
                        <p><span class="copiable" @click="copy($event)">/{{ $ciCdOptions->build_folder }}</span> -> <span>/</span></p>
                    </div>
                @endif
            </div>

            @if($isBackend)
                <div class="mt-6 mb-2">
                    <p class="font-bold">
                        <x-heroicon-o-circle-stack class="w-7 h-7 inline-block mr-1"/>
                        Connect to databases
                    </p>
                    <div class="">
                        <p>Database: <span class="copiable" @click="copy($event)">{{ $stageConfig['database']['database'] }}</span></p>
                        <p>User: <span class="copiable" @click="copy($event)">{{ $stageConfig['database']['username'] }}</span></p>
                        <p>Password: <span class="copiable" @click="copy($event)">{{ $stageConfig['database']['password'] }}</span></p>
                    </div>
                </div>

                <div class="mt-6 mb-2">
                    <p class="font-bold">
                        <x-heroicon-o-clock class="w-7 h-7 inline-block mr-1"/>
                        Configure crontab / scheduler
                    </p>
                    <div class="">
                        <p class="italic">To open crontab editor, execute:</p>
                        <p><span class="copiable" @click="copy($event)">crontab -e</span></p>
                        <p class="italic">and write:</p>
                        <p class="copiable" @click="copy($event)">* * * * * cd {{$DEPLOY_BASE_DIR}}/current && {{$BIN_PHP}} artisan schedule:run >> /dev/null 2>&1</p>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
