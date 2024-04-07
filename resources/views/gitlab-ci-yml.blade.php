# See more about templates, their usage and configuration at:
# https://gitlab.hexide-digital.com/packages/gitlab-templates#laravel-templates

include:
  - project: 'packages/gitlab-templates'
    ref: master
    file: 'templates/laravel.{{ $templateVersion }}.gitlab-ci.yml'

@if($buildStageEnabled)
# We need to specify the Node.js version you are using to build the resources,
# as the Node.js version in the template will be up to date and may not be compatible with your project.
variables:
  NODE_VERSION: "{{ $nodeVersion }}"
@endif
