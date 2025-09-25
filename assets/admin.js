/*
 * Skrypty administracyjne dla License Server
 * Użyj tego pliku do dynamicznego ładowania danych lub obsługi formularzy w panelu administratora.
 */
(function($){
    $(function(){
        console.log('License Server admin JS loaded');
        // Przykładowy kod: toggle display of fields
        $(document).on('change', '#_lsr_is_licensed', function(){
            var checked = $(this).is(':checked');
            $('#_lsr_max_activations, #_lsr_slug').closest('p').toggle(checked);
        });
    });
})(jQuery);
