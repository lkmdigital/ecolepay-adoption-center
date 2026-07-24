<?php

use App\Domains\Dashboard\Actions\BuildAdoptionSummary;
use App\Domains\Dashboard\Data\AdoptionSummary;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Propriété calculée : le DTO est reconstruit à chaque rendu et ne
     * transite jamais par l'état sérialisé du composant.
     */
    #[Computed]
    public function summary(): AdoptionSummary
    {
        return app(BuildAdoptionSummary::class)();
    }
};
?>

<div class="p-8 space-y-6">
    <flux:heading size="xl">Tableau de bord</flux:heading>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text>Écoles</flux:text>
            <flux:heading size="lg" data-testid="schools">{{ $this->summary->schools }}</flux:heading>
        </flux:card>

        <flux:card>
            <flux:text>Parents</flux:text>
            <flux:heading size="lg" data-testid="parents">{{ $this->summary->parents }}</flux:heading>
        </flux:card>

        <flux:card>
            <flux:text>Taux d'adoption</flux:text>
            <flux:heading size="lg" data-testid="rate">{{ $this->summary->formattedRate() }}</flux:heading>
        </flux:card>
    </div>
</div>
