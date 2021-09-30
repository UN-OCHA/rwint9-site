/* global mapboxgl */
(function () {
  'use strict';

  if (!window.reliefweb) {
    window.reliefweb = {};
  }

  window.reliefweb.mapbox = {
    // Store map settings.
    maps: {},
    // Set the key and token needed to create maps.
    init: function (key, token) {
      this.key = key;
      this.token = token;
    },
    // Check if mapbox is supported.
    supported: function (strict) {
      // Do not show on small screens as it's too tiny.
      if (document.documentElement.clientWidth < 768) {
        return false;
      }
      return this.key && this.token && mapboxgl && mapboxgl.supported({
        failIfMajorPerformanceCaveat: strict === true
      });
    },
    // Constrain `n` to the given range.
    // @see unexposed mapboxgl util.wrap().
    wrap: function (n, min, max) {
      const d = max - min;
      const w = ((((n - min) % d) + d) % d) + min;
      return w === min ? max : w;
    },
    // Add a map's settings.
    addMap: function (id, settings) {
      this.maps[id] = settings;
    },
    // Get a map's settings.
    getMap: function (id) {
      return this.maps[id] || {};
    }
  };
})();
