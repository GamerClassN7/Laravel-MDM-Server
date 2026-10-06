// A <details> the user opened or closed keeps that state when Livewire re-renders the component (a
// live update morphs the DOM to what the server renders, which has the server's default state).
document.addEventListener('toggle', (event) => {
    if (event.target instanceof HTMLDetailsElement) {
        event.target.dataset.userState = event.target.open ? 'open' : 'closed';
    }
}, true);

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morph.updating', ({ el, toEl }) => {
        if (el instanceof HTMLDetailsElement && toEl instanceof HTMLDetailsElement && el.dataset.userState) {
            if (el.dataset.userState === 'open') {
                toEl.setAttribute('open', '');
            } else {
                toEl.removeAttribute('open');
            }
            toEl.dataset.userState = el.dataset.userState;
        }
    });
});
