<?php

declare(strict_types=1);

namespace App\Domains\DeployConfigurator\Helpers\Builders;

use HexideDigital\GitlabDeploy\Gitlab\Variable;
use HexideDigital\GitlabDeploy\Gitlab\VariableBag;
use HexideDigital\GitlabDeploy\Helpers\Replacements;

final class VariableBagBuilder
{
    public function __construct(
        private readonly Replacements $replacements,
        private readonly string $stageName,
    ) {
    }

    public function build(bool $isFrontend = false): VariableBag
    {
        $bag = new VariableBag();

        $variables = [
            'DEPLOY_BASE_DIR' => $this->replacements->replace('{{DEPLOY_BASE_DIR}}'),
            'DEPLOY_SERVER' => $this->replacements->replace('{{DEPLOY_SERVER}}'),
            'DEPLOY_USER' => $this->replacements->replace('{{DEPLOY_USER}}'),
            'SSH_PORT' => $this->replacements->replace('{{SSH_PORT}}'),

            'SSH_PRIVATE_KEY' => '-----BEGIN OPENSSH PRIVATE ',
            'SSH_PUB_KEY' => 'rsa-ssh AAA....AAA user@host',

            'CI_ENABLED' => '0',
        ];

        if ($isFrontend) {
            $variables = array_merge($variables, [
                //
            ]);
        } else {
            $variables = array_merge($variables, [
                'BIN_PHP' => $this->replacements->replace('{{BIN_PHP}}'),
                'BIN_COMPOSER' => $this->replacements->replace('{{BIN_COMPOSER}}'),
            ]);
        }

        foreach ($variables as $key => $value) {
            $variable = new Variable(
                key: $key,
                scope: $this->stageName,
                value: $value,
            );

            $bag->add($variable);
        }

        return $bag;
    }
}
