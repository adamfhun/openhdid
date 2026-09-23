{{-- Panel-wide keyboard shortcut: Ctrl/Cmd+K jumps to the client search. --}}
<script>
    document.addEventListener('keydown', (event) => {
        if (! (event.ctrlKey || event.metaKey) || event.altKey || event.key.toLowerCase() !== 'k') {
            return;
        }

        event.preventDefault();
        window.location.assign(@js($searchUrl));
    });
</script>
