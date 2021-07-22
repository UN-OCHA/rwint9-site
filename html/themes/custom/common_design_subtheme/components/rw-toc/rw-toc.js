(function (Drupal) {
  'use strict';

  Drupal.behaviors.rwTableOfContents = {
    attach: function (context, settings) {

      /**
       * Observe targets of anchor links in a table of contents, to mark them as
       * active when they become the most prominent section in the viewport.
       */
      function observeAnchorTargets() {
        if (!window.IntersectionObserver) {
          return;
        }

        // Get the table of contents.
        var toc = document.getElementById('table-of-contents');
        if (!toc) {
          return;
        }

        // Get the list of anchors from the table of contents.
        var anchors = toc.querySelectorAll('[href^="#"]');
        if (!anchors || anchors.length === 0) {
          return;
        }

        // Get the targets of the anchors.
        var elements = [];
        for (var i = 0, l = anchors.length; i < l; i++) {
          var href = anchors[i].getAttribute('href');
          if (href !== '#') {
            var element = document.getElementById(href.substr(1));
            if (element) {
              elements.push(element);
            }
          }
        }
        if (elements.length === 0) {
          return;
        }

        // Track the active element, the scrolling position and the intersecting
        // elements.
        var active = null;
        var scrollY = 0;
        var clicked = true;

        // Update the active element.
        var updateActive = function (candidate) {
          if (candidate !== active) {
            active = candidate;

            // Update the active section.
            for (var i = 0, l = elements.length; i < l; i++) {
              var element = elements[i];
              if (element === active) {
                element.setAttribute('data-active', '');
              }
              else {
                element.removeAttribute('data-active');
              }
            }

            // Update the corresponding table of contents entry.
            var href = '#' + active.getAttribute('id');
            for (var i = 0, l = anchors.length; i < l; i++) {
              var anchor = anchors[i];
              if (anchor.getAttribute('href') === href) {
                anchor.setAttribute('data-active', '');
              }
              else {
                anchor.removeAttribute('data-active');
              }
            }
          }
        };

        // Get the active element based on the viewport height and scroll direction.
        var getActiveCandidate = function (height, down) {
          var index = observed[active.id];
          var last = elements[elements.length - 1];
          var next = elements[index + 1];
          var previous = elements[index - 1];

          // Scrolling down.
          if (down) {
            // Active is the last element, skip.
            if (!next) {
              return active;
            }

            // Last element is inside the viewport, move to next as we are reaching
            // the scolling end.
            if (last.getBoundingClientRect().bottom < height) {
              return next;
            }
            // Active's top is still inside viewport, keep it as active.
            else if (active.getBoundingClientRect().top > 0) {
              return active;
            }
            // Next is in the upper half, set it as active.
            else if (next.getBoundingClientRect().top < height / 2) {
              return next;
            }

            // Active is still the most prominently visible section, keep it.
            return active;
          }
          // Scrolling up.
          else {
            // Active is the first, skip.
            if (!previous) {
              return active;
            }

            // Previous is not in viewport, skip.
            var previousBounds = previous.getBoundingClientRect();
            if (previousBounds.bottom < 0) {
              return active;
            }

            // Previous top is not in the viewport and active is still in top half,
            // skip.
            var activeBounds = previous.getBoundingClientRect();
            if (previousBounds.top < 0 && activeBounds.top < height / 2) {
              return active;
            }

            // Set the previous element as active.
            return previous;
          }
        };


        // React to the sections intersecting the viewport to update the
        // active section to highlight.
        var intersectionObserver = new IntersectionObserver(function (entries) {
          // Skip if the intersection event results from clicking on a link in the
          // the table of contents as we already set the active element then.
          if (clicked) {
            clicked = false;
          }
          // Only proceed if we have a valid active element.
          else if (active) {
            // Viewport height.
            var height = entries[0].rootBounds.height;
            // Scroll direction.
            var down = window.scrollY >= scrollY;
            // Update the candidate section to highlight.
            updateActive(getActiveCandidate(height, down));
          }
          // Keep track of the current scrolling position.
          scrollY = window.scrollY;
        }, {
          // Monitor several ratio thresholds to ensure we can update the active
          // element when scrolling the page.
          threshold: [0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1]
        });

        // Start observing. We keep track of the indices of the tracked elements.
        var observed = {};
        for (var i = 0, l = elements.length; i < l; i++) {
          var element = elements[i];
          observed[element.id] = i;
          intersectionObserver.observe(element);
        }

        // Get an element based on a url's fragment.
        var getElement = function (hash) {
          return hash[0] === '#' ? elements[observed[hash.substr(1)]] : null;
        };

        // Update the active element when clicking a link in the table of contents.
        // We also set the clicked flag to true so that the active element is not
        // updated by the intersection event caused by jumping to the new section.
        toc.addEventListener('mousedown', function (event) {
          var target = event.target;
          if (event.target.nodeName === 'A') {
            var element = getElement(target.getAttribute('href'));
            if (element) {
              updateActive(element);
              clicked = true;
            }
          }
        });

        // Set the initial active element.
        updateActive(getElement(location.hash) || elements[0]);
      }

      observeAnchorTargets();

    }
  };
})(Drupal);
