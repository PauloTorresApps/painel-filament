@props([
    'user' => auth()->user(),
    'size' => 'sm',
    'class' => '',
])

@php
    $sizeClasses = match($size) {
        'xs' => 'h-6 w-6 text-xs',
        'sm' => 'h-8 w-8 text-sm',
        'md' => 'h-10 w-10 text-sm',
        'lg' => 'h-12 w-12 text-base',
        'xl' => 'h-16 w-16 text-lg',
        default => 'h-8 w-8 text-sm',
    };
@endphp

<span class="relative flex {{ $sizeClasses }} shrink-0 overflow-hidden rounded-lg {{ $class }}">
    @if($user->hasProfilePhoto())
        <img
            src="{{ $user->profilePhotoUrl() }}"
            alt="{{ $user->name }}"
            class="h-full w-full object-cover rounded-lg"
        />
    @else
        <span class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
            {{ $user->initials() }}
        </span>
    @endif
</span>
