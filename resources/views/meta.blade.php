@if(!\Illuminate\Support\Facades\App::isProduction())
    <meta name="robots" content="noindex, nofollow">
@else
    <meta name="robots" content="noindex, nofollow">
{{--    <meta name="robots" content="index, follow">--}}
@endif

<meta name="description" content="GitLab Deployer is a tool for deploying Laravel projects.">
<meta name="keywords" content="laravel, deployer, deploy, deployment, laravel deployer, laravel deploy">
<meta name="author" content="GitLab Deployer">

<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="GitLab Deployer">
<meta name="application-name" content="GitLab Deployer">

<meta property="og:title" content="GitLab Deployer">
<meta property="og:description" content="GitLab Deployer is a tool for deploying Laravel projects.">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ config('app.url') }}">
<meta property="og:image" content="{{ config('app.url') }}/images/meta.png">
<meta property="og:site_name" content="GitLab Deployer">
<meta property="og:locale" content="en_US">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="GitLab Deployer">
<meta name="twitter:description" content="GitLab Deployer is a tool for deploying Laravel projects.">
<meta name="twitter:image" content="{{ config('app.url') }}/images/meta.png">
<meta name="twitter:image:alt" content="GitLab Deployer">
