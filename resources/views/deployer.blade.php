<{{ '?php' }}

/**
 * Keep this version number to manage file versions in projects
 *
 * @version 1.4
 */

namespace Deployer;

require 'recipe/laravel.php';
require 'contrib/rsync.php';

/* ----------------------------------
 * Configs
 */

$applicationName = '{{ $applicationName }}';
$github_oauth_token = '{{ $githubOathToken }}';

/* ----------------------------------
 * Gitlab CI/CD variables
 */

// prepare variables from environment CI_ENV
$IDENTITY_FILE = '~/.ssh/id_rsa';

$CI_REPOSITORY_URL = getenv('CI_REPOSITORY_URL');
$CI_COMMIT_REF_NAME = getenv('CI_COMMIT_REF_NAME');
$BIN_PHP = getenv('BIN_PHP');
$BIN_COMPOSER = getenv('BIN_COMPOSER');
$DEPLOY_BASE_DIR = getenv('DEPLOY_BASE_DIR');
$DEPLOY_SERVER = getenv('DEPLOY_SERVER');
$DEPLOY_USER = getenv('DEPLOY_USER');
$SSH_PORT = getenv('SSH_PORT');
/*CI_ENV*/ // - do not remote this comment. Uses for replacement.
@if($renderVariables)

/* -------------------------------------------------------------- */
// todo remove generated values
/**/        $IDENTITY_FILE = __DIR__ . '/.ssh/id_rsa';
/**/        $CI_REPOSITORY_URL = '{{ $CI_REPOSITORY_URL }}';
/**/        $CI_COMMIT_REF_NAME = '{{ $CI_COMMIT_REF_NAME }}';
/**/        $BIN_PHP = '{{ $BIN_PHP }}';
/**/        $BIN_COMPOSER = '{{ $BIN_COMPOSER }}';
/**/        $DEPLOY_BASE_DIR = '{{ $DEPLOY_BASE_DIR }}';
/**/        $DEPLOY_SERVER = '{{ $DEPLOY_SERVER }}';
/**/        $DEPLOY_USER = '{{ $DEPLOY_USER }}';
/**/        $SSH_PORT = '{{ $SSH_PORT }}';
/* -------------------------------------------------------------- */
@endif

/* ----------------------------------
 * Configs
 */

set('application', $applicationName);

// github token
set('github_oauth_token', $github_oauth_token);

// Project git repository url
set('repository', $CI_REPOSITORY_URL);

// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', false);
set('allow_anonymous_stats', false);
set('keep_releases', 1);

set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --optimize-autoloader');


/* ----------------------------------
 * rsync settings
 */

set('rsync_src', __DIR__ . '/public');
set('rsync_dest', '@{{release_path}}/public');

set('rsync', [
    'exclude' => [],
    'exclude-file' => false,
    'include' => [],
    'include-file' => false,
    'filter' => [],
    'filter-file' => false,
    'filter-perdir' => false,
    'flags' => 'rz', // Recursive, with compress
    'options' => [],
    'timeout' => 60,
]);


/* ----------------------------------
 * Hosts settings
 */

host($DEPLOY_SERVER)
    ->setHostname($DEPLOY_SERVER)
    ->setLabels(['stage' => $CI_COMMIT_REF_NAME])
    ->setPort(intval($SSH_PORT))
    ->set('remote_user', $DEPLOY_USER)
    ->set('branch', $CI_COMMIT_REF_NAME)
    ->set('deploy_path', $DEPLOY_BASE_DIR)
    ->set('http_user', $DEPLOY_USER)
    ->set('bin/php', $BIN_PHP)
    ->set('bin/composer', $BIN_COMPOSER)
    ->setIdentityFile($IDENTITY_FILE)
    ->setSshArguments([
        '-o UserKnownHostsFile=/dev/null',
        '-o StrictHostKeyChecking=no',
        '-o IdentitiesOnly=yes',
    ]);


/* ----------------------------------
 * Main deploy tasks
 */

// Hooks

after('deploy:failed', 'deploy:unlock');


// Additional tasks

before('deploy:shared', 'rsync');

before('artisan:migrate', function () {
    $releaseNumber = intval(get('release_name'));
    // 3 attempts to successfully deploy and migrate
    if ($releaseNumber > 3) {
        invoke('artisan:migrate:status');
    }
});

// if project uses Telescope, recommended prune old entries
//after('artisan:migrate', 'artisan:telescope:prune');


// Maintenance mode

//after('deploy:vendors', 'artisan:down');

//after('deploy:unlock', 'artisan:up');

/*
 * Note:
 * `deploy` task copied from `recipe/laravel.php` file
 * and removed tasks for caching
 */
desc('Deploys your project');
task('deploy', [
    'deploy:prepare',

    'deploy:vendors',
    'artisan:storage:link',

    // remove all cached files or run
    'artisan:optimize:clear',
    // or call individually only needed
    /* 'artisan:cache:clear',    */
    /* 'artisan:config:clear',   */
    /* 'artisan:event:clear',    */
    /* 'artisan:optimize:clear', */
    /* 'artisan:route:clear',    */
    /* 'artisan:view:clear',     */

    'artisan:migrate',

    'deploy:publish',
]);
