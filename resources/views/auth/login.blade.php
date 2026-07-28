<?php

use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            throw ValidationException::withMessages([
                'email' => "Identifiants incorrects. Vérifiez votre e-mail et votre mot de passe.",
            ]);
        }

        $user = Auth::user();

        // Un compte désactivé ne doit pas ouvrir de session.
        if (! $user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => "Ce compte est désactivé. Contactez un administrateur.",
            ]);
        }

        $user->recordLogin();
        session()->regenerate();

        $this->redirectIntended(default: route('dashboard.index'), navigate: false);
    }

    /** Connexion rapide en dév : pré-remplit un compte de démonstration. */
    public function useDemo(string $email): void
    {
        $this->email = $email;
        $this->password = 'password';
    }
};

?>

@php
    // Comptes de démonstration (mot de passe commun en environnement de dév).
    $demo = [
        ['admin@ecolepay.ci', 'Super Admin'],
        ['direction@ecolepay.ci', 'Direction'],
        ['marketing@ecolepay.ci', 'Marketing'],
        ['commercial@ecolepay.ci', 'Commercial'],
    ];
@endphp

<div class="rounded-[16px] border border-ink-200 bg-white p-6 shadow-[0_2px_10px_rgba(15,23,42,0.05)] sm:p-7">
    <h1 class="text-[17px] font-bold tracking-tight text-ink-900">Connexion</h1>
    <p class="mt-1 text-[12.5px] text-ink-500">Accédez à votre espace de pilotage.</p>

    <form wire:submit="login" class="mt-5 flex flex-col gap-4">
        <div>
            <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Adresse e-mail</label>
            <input wire:model="email" type="email" autocomplete="username" autofocus
                   @class(['eac-input', 'border-danger' => $errors->has('email')]) placeholder="vous@ecolepay.ci">
            @error('email') <p class="eac-err">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1.5 block text-[12.5px] font-semibold text-ink-800">Mot de passe</label>
            <input wire:model="password" type="password" autocomplete="current-password"
                   @class(['eac-input', 'border-danger' => $errors->has('password')]) placeholder="••••••••">
            @error('password') <p class="eac-err">{{ $message }}</p> @enderror
        </div>

        <label class="flex cursor-pointer items-center gap-2 text-[12.5px] text-ink-600">
            <input wire:model="remember" type="checkbox" class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600">
            Rester connecté
        </label>

        <button type="submit" wire:loading.attr="disabled" wire:target="login"
                class="mt-1 inline-flex items-center justify-center gap-2 rounded-[10px] bg-brand-600 px-4 py-2.5 text-[13.5px] font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 disabled:opacity-60">
            <svg wire:loading wire:target="login" width="16" height="16" viewBox="0 0 20 20" fill="none" class="animate-spin"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/><path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            Se connecter
        </button>
    </form>

    {{-- Comptes de démonstration (dév) --}}
    <div class="mt-6 border-t border-ink-150 pt-4" x-data="{ open: false }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-[12px] font-semibold text-ink-500 hover:text-ink-800">
            Comptes de démonstration
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="transition-transform" :class="{ 'rotate-180': open }"><path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <div x-show="open" x-collapse x-cloak>
            <p class="mt-2 text-[11px] text-ink-400">Mot de passe commun : <span class="font-mono font-semibold text-ink-600">password</span>. Cliquez pour pré-remplir.</p>
            <div class="mt-2 flex flex-col gap-1.5">
                @foreach ($demo as [$mail, $role])
                    <button type="button" wire:click="useDemo('{{ $mail }}')"
                            class="flex items-center justify-between gap-2 rounded-[8px] border border-ink-200 px-3 py-2 text-left text-[12px] hover:border-brand-300 hover:bg-brand-50/40">
                        <span class="font-mono text-ink-700">{{ $mail }}</span>
                        <span class="rounded-full bg-ink-100 px-2 py-0.5 text-[10.5px] font-bold text-ink-600">{{ $role }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</div>
