var google_maps_loaded_def = null;

define(['jquery'],function ($)
{
    if (!google_maps_loaded_def)
    {
        google_maps_loaded_def = $.Deferred();
        window.google_maps_loaded = function ()
        {
            google_maps_loaded_def.resolve(google.maps);
        };

        var apiKey =  window.checkoutConfig.hop.api_key;
        var url = 'https://maps.googleapis.com/maps/api/js?key=' + apiKey + '&callback=google_maps_loaded';

        require([url], function () {}, function (err) {
            google_maps_loaded_def.reject();
        });
    }

    return google_maps_loaded_def.promise();
});