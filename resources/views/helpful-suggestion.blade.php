@php
    /**
     * @var array $stages
     * @var array $stageConfig
     */
@endphp

@foreach($stages as $stageConfig)
    @php
        $server = $stageConfig['server']['host'];
        $user = $stageConfig['server']['login'];

        $DEPLOY_BASE_DIR = \Illuminate\Support\Str::replace(
                    ['{{HOST}}', '{{USER}}'],
                    [$server, $user],
                    $stageConfig['options']['base-dir-pattern']
                );
        $DEPLOY_SERVER = $server;
        $BIN_PHP = $stageConfig['options']['bin-php'];
        $SSH_PORT = $stageConfig['server']['ssh-port'] ?? 22;
        $DEPLOY_DOMAIN = $stageConfig['server']['domain'];

        $DB_DATABASE = $stageConfig['database']['database'];
        $DB_USERNAME = $stageConfig['database']['username'];
        $DB_PASSWORD = $stageConfig['database']['password'];
    @endphp

    <div class="mb-2 space-y-3">
        <div class="">
            <h2>{{$stageConfig['name']}}</h2>
        </div>

        <div class="">
            <p class="text-blue-500 font-bold">Mount path</p>
            <span>{{$DEPLOY_BASE_DIR}}</span>
        </div>

        <div class="">
            <p class="text-blue-500 font-bold">Site url</p>
            <span>{{$DEPLOY_DOMAIN}}</span>
        </div>

        <div class="">
            <p class="text-blue-500 font-bold">Add mapping for deployment</p>
            <span>/current</span>
        </div>
        <div class="">
            <p class="text-blue-500 font-bold">Configure crontab / scheduler</p>
            <div class="">
                <div class="mb-1">
                    <div class="italic">To open crontab editor, execute:</div>
                    <div class="text-green-500">crontab -e</div>
                </div>
                <div class="italic">and write:</div>
                <div class="text-green-500">* * * * * cd {{$DEPLOY_BASE_DIR}}/current && {{$BIN_PHP}} artisan schedule:run >> /dev/null 2>&1</div>
            </div>
        </div>

        <div class="">
            <p class="text-blue-500 font-bold">Connect to databases</p>
            <div class="">
                <div>port: {{$SSH_PORT}}</div>
                <div>domain: {{$DEPLOY_DOMAIN}}</div>
                <div>db_name: {{$DB_DATABASE}}</div>
                <div>db_user: {{$DB_USERNAME}}</div>
                <div>password: {{$DB_PASSWORD}}</div>
            </div>
        </div>
    </div>
@endforeach
