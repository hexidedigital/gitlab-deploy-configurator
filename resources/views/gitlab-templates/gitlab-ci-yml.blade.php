# See more about templates, their usage and configuration at:
# https://gitlab.hexide-digital.com/packages/gitlab-templates

include:
  - project: 'packages/gitlab-templates'
    ref: master
    file: 'templates/{{ $templateInfo->templateName }}.gitlab-ci.yml'

@if($variables)
@if(isset($variables['NODE_VERSION']))
# We need to specify the Node.js version you are using to build the resources,
# as the Node.js version in the template will be up to date and may not be compatible with your project.
@endif
variables:
@foreach($variables as $name => $variable)
  {{ $name }}: "{{ $variable['value'] }}"@if(!empty($variable['comment'])) # {{ $variable['comment'] }}@endif

@endforeach
@endif
