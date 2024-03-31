# See more about templates, their usage and configuration at:
# https://gitlab.hexide-digital.com/packages/gitlab-templates#laravel-templates

include:
  - project: 'packages/gitlab-templates'
    ref: master
    file: 'templates/laravel.{{ $templateVersion }}.gitlab-ci.yml'

Build:
  image: node:{{ $nodeVersion }}
