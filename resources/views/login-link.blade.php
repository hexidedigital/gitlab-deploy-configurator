@env(config('login-link.allowed_environments'))
    <div class="space-y-2 text-center">
        <x-login-link
            :email="'admin@admin.com'"
            class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-primary fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 focus-visible:ring-custom-500/50 dark:focus-visible:ring-custom-400/50 fi-ac-btn-action"
            redirect-url="/dashboard"
            label="Login as admin"
        />
    </div>
@endenv
