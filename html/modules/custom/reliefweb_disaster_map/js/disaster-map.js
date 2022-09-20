/* global mapboxgl reliefweb */
(function (Drupal) {

  'use strict';

  Drupal.behaviors.reliefwebDisasterMap = {
    attach: function (context, settings) {
      // Requirements.
      if (!reliefweb || !reliefweb.mapbox || !settings || !settings.reliefwebDisasterMap) {
        return;
      }

      // Initialize the RW mapbox handler so we can check support.
      reliefweb.mapbox.init(settings.reliefwebDisasterMap.mapboxKey, settings.reliefwebDisasterMap.mapboxToken);

      // Skip if the browser doesn't support mapbox GL.
      if (!reliefweb.mapbox.supported()) {
        // Mark the map as disabled.
        var maps = document.querySelectorAll('[data-disaster-map]');
        for (var i = 0, l = maps.length; i < l; i++) {
          maps[i].removeAttribute('data-map-enabled');
        }
        return;
      }

      // Redirect to a disaster's page.
      function redirect(disaster) {
        var link = disaster.querySelector('header a');
        if (link) {
          window.location.href = link.getAttribute('href');
        }
      }

      // Set active marker.
      function setActiveMarker(map, marker, active, click) {
        // If the active marker is the same marker and it was clicked, redirect
        // to the disaster page, otherwise, just skip as it stays active.
        if (active === marker) {
          if (click === true) {
            redirect(marker.disaster);
          }
        }
        else {
          // Unset the previously active marker.
          unsetActiveMarker(active);

          // Determine if the description should be displayed on the left or right.
          var center = map.getCenter().wrap();
          var lnglat = marker.getLngLat().wrap();
          var position = center.lng > lnglat.lng ? 'right' : 'left';

          // Mark the clicked or hovered marker as being active and show the
          // disaster description.
          marker.getElement().setAttribute('data-active', '');
          marker.disaster.setAttribute('data-active', position);
        }
        return marker;
      }

      // Unset active marker.
      function unsetActiveMarker(active) {
        if (active) {
          active.getElement().removeAttribute('data-active');
          active.disaster.removeAttribute('data-active');
        }
        return null;
      }

      // Create a disaster marker.
      function createMarker(id, node) {
        var element = document.createElement('div');
        element.setAttribute('data-id', id);
        element.setAttribute('data-disaster-status', node.getAttribute('data-disaster-status'));
        element.setAttribute('data-disaster-type', node.getAttribute('data-disaster-type'));

        node.setAttribute('data-marker-id', id);

        var marker = new mapboxgl.Marker({
          element: element,
          anchor: 'bottom'
        });
        marker.setLngLat({
          // Try to make the coordinate consistent. Note, when using the [-180, 180]
          // range and we have points with a negative longitude and other with a
          // positive one at the extremities of the maps but actually clustered like
          // pacific islands, then the bounds span the entire map instead of the
          // area where the points actually are.
          lon: reliefweb.mapbox.wrap(node.getAttribute('data-disaster-lon'), -180, 180),
          lat: node.getAttribute('data-disaster-lat')
        });
        marker.id = id;
        marker.disaster = node;
        marker.disasterLink = node.querySelector('a');
        return marker;
      }

      // Find a parent disaster article from a child element.
      function findParentArticle(container, element) {
        while (element && element !== container) {
          if (element.hasAttribute('data-marker-id')) {
            return element;
          }
          element = element.parentNode;
        }
        return null;
      }

      // Create the map legend.
      function createLegend(legend) {
        var container = document.createElement('figcaption');
        container.className = 'rw-disaster-map__legend';
        container.setAttribute('aria-hidden', true);
        for (var status in legend) {
          if (legend.hasOwnProperty(status)) {
            var part = document.createElement('span');
            part.className = 'rw-disaster-map__legend__item';
            part.setAttribute('data-disaster-status', status);
            part.appendChild(document.createTextNode(legend[status]));
            container.appendChild(part);
          }
        }
        return container;
      }
      // Create the map.
      function createMap(element) {
        // Skip if the map was already processed.
        if (element.hasAttribute('data-map-processed')) {
          return;
        }
        // Mark the map has being processed.
        element.setAttribute('data-map-processed', '');

        // Map settings.
        var mapSettings = settings.reliefwebDisasterMap.maps[element.getAttribute('data-disaster-map')];

        // Create the container with the legend.
        var container = element.querySelector('[data-map-content]');
        var figure = document.createElement('figure');
        var mapContainer = document.createElement('div');
        figure.className = 'rw-disaster-map__map';
        figure.appendChild(mapContainer);
        figure.appendChild(createLegend(mapSettings.legend || {}));
        figure.setAttribute('data-loading', '');
        figure.setAttribute('aria-hidden', true);

        // Create a button to close the popup with the disaster description.
        var button = document.createElement('button');
        button.className = 'rw-disaster-map__close';
        button.setAttribute('type', 'button');
        button.appendChild(document.createTextNode(mapSettings.close));
        figure.appendChild(button);

        // Add the map containing figure.
        container.appendChild(figure);

        var markers = {};
        var active = null;
        var bounds = new mapboxgl.LngLatBounds();

        // Create a marker for each disaster. They will be added to the map
        // later. We create them first so we can calculate their bounds.
        var nodes = element.querySelectorAll('article[data-disaster-status]');
        for (var i = 0, l = nodes.length; i < l; i++) {
          var id = 'marker-' + i;
          var marker = createMarker(id, nodes[i]);
          // Extend the bounding box that will be used to set the initial bounds
          // of the disaster map.
          bounds.extend(marker.getLngLat());
          // Keep track of ther marker so we can display the disaster description
          // when hovering/clicking on it.
          markers[id] = marker;
        }

        // Map options.
        var options = {
          accessToken: reliefweb.mapbox.token,
          style: 'mapbox://styles/reliefweb/' + reliefweb.mapbox.key + '?optimize=true',
          container: mapContainer,
          center: [10, 10],
          zoom: 1,
          doubleClickZoom: false,
          minZoom: 1,
          maxZoom: 4
        };

        // Set the initial map zoom and center based on the bounding box of the
        // disaster locations.
        if (mapSettings.fitBounds === true) {
          options.bounds = bounds;
          options.fitBoundsOptions = {
            // Max zoom is to avoid the map from being unreadable. A zoom of 4
            // allows most of the time to see the affected country entirely when
            // there is for example a single disaster.
            maxZoom: 4,
            // The padding is to accomodate the zoom controls and mapbox branding.
            padding: 64
          };
          options.minZoom = 0;
        }

        // Create a map.
        var map = new mapboxgl.Map(options)
        // Add the zoom control buttons, bottom left to limit overlap with the
        // disaster description popup.
        .addControl(new mapboxgl.NavigationControl({
          showCompass: false
        }), 'bottom-left')
        // Add the markers to the map.
        .on('load', function (event) {
          for (var marker in markers) {
            if (markers.hasOwnProperty(marker)) {
              markers[marker].addTo(map);
            }
          }
          // Mark the map as enabled and ready for display.
          element.setAttribute('data-map-enabled', '');
          figure.removeAttribute('data-loading');
        })
        // Restore visibility of the list if the map couldn't be loaded.
        .on('error', function (event) {
          element.removeAttribute('data-map-enabled');
          element.removeChild(figure);
        })
        // Unset the active marker when clicking on the map.
        .on('click', function (event) {
          var target = event.originalEvent.target;
          if (!target.hasAttribute || !target.hasAttribute('data-id')) {
            active = unsetActiveMarker(active);
          }
        });

        // Set a marker as the active one when clicking on it.
        container.addEventListener('click', function (event) {
          var target = event.target;
          if (target.hasAttribute && target.hasAttribute('data-id')) {
            markers[target.getAttribute('data-id')].disasterLink.focus();
          }
          else {
            active = unsetActiveMarker(active);
          }
        });

        // Unset the active marker when pressing escape.
        container.addEventListener('keydown', function (event) {
          if (event.keyCode === 27) {
            active = unsetActiveMarker(active);
          }
        });

        // Set a marker as the active one when focusing its disaster article.
        container.addEventListener('focusin', function (event) {
          var article = findParentArticle(container, event.target);
          if (article && article.hasAttribute && article.hasAttribute('data-marker-id')) {
            active = setActiveMarker(map, markers[article.getAttribute('data-marker-id')], active, false);
          }
          else {
            active = unsetActiveMarker(active);
          }
        });

        // Disable map zoom when scrolling.
        map.scrollZoom.disable();
      }

      // Create the maps.
      var maps = document.querySelectorAll('[data-disaster-map]');
      for (var i = 0, l = maps.length; i < l; i++) {
        createMap(maps[i]);
      }
    }
  };
})(Drupal);
