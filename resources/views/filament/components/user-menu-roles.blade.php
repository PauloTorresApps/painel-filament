@if(auth()->check())
    @php
        $roleColors = [
            'Admin' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/30',
            'Manager' => 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30',
            'Default' => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/30',
            'Analista de Processo' => 'bg-purple-50 text-purple-700 ring-purple-600/20 dark:bg-purple-400/10 dark:text-purple-400 dark:ring-purple-400/30',
            'Analista de Contrato' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-400/10 dark:text-emerald-400 dark:ring-emerald-400/30',
        ];
        $defaultColor = 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/30';
    @endphp

    <div class="px-2 pb-1">
        <div class="flex flex-wrap gap-1">
            @foreach(auth()->user()->getRoleNames() as $role)
                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[9px] font-medium ring-1 ring-inset {{ $roleColors[$role] ?? $defaultColor }}">
                    {{ $role }}
                </span>
            @endforeach
        </div>
    </div>
@endif
