<?php

namespace App\Livewire\User;

use App\Models\User;
use App\Support\Locales;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** The language of the user interface in the profile (App\Support\Locales). */
class Language extends Component
{
    public User $user;

    public string $locale = '';

    protected function rules()
    {
        return ['locale' => ['required', Rule::in(array_keys(Locales::available()))]];
    }

    public function mount(User $user)
    {
        $this->user = $user;
        $this->locale = Locales::ofUser($user) ?? app()->getLocale();
    }

    #[Computed]
    public function locales()
    {
        return Locales::available();
    }

    public function store()
    {
        $validated = $this->validate();
        Locales::setForUser($this->user, $validated['locale']);
        app()->setLocale($validated['locale']);

        session()->flash('success', __('Saved'));

        // Reloaded, so the whole page is in the new language.
        return $this->redirectRoute('profile.index');
    }

    public function render()
    {
        return view('livewire.user.language');
    }
}
