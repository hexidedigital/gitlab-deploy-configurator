@if($artisanCompletion)
# Complete artisan commands
_artisan()
{
    local arg="${COMP_LINE#php }"

    case "$arg" in
        a*)
            COMP_WORDBREAKS=${COMP_WORDBREAKS//:}
            COMMANDS=`artisan --raw --no-ansi list | sed "s/[[:space:]].*//g"`
            COMPREPLY=(`compgen -W "$COMMANDS" -- "${COMP_WORDS[COMP_CWORD]}"`)
            ;;
        *)
            COMPREPLY=( $(compgen -o default -- "${COMP_WORDS[COMP_CWORD]}") )
            ;;
        esac

    return 0
}

complete -F _artisan artisan
complete -F _artisan a
@endif

@if($artisanAliases)
alias artisan="{{$BIN_PHP}} artisan"
alias a="artisan"
@endif

@if($composerAlias)
alias pcomopser="{{$BIN_COMPOSER}}"
@endif

@if($folderAliases)
export BASE_DIR="{{$DEPLOY_BASE_DIR}}"
export CURRENT_DIR="$BASE_DIR/current"
export SHARED_DIR="$BASE_DIR/shared"

alias cur="cd $CURRENT_DIR"
alias shared="cd $SHARED_DIR"
@endif
