(function () {
  'use strict';

  window.Searcher = function () {
    var matchRegexps = [];
    var highlightRegexp = null;
    var matchedColors = {};
    var colors = ['yellow', 'red', 'green', 'blue', 'purple'];
    var colorIndex = 0;
    /* eslint-disable */
    var diacriticsMap = {
      '\u00E0': 'a',  // à => a
      '\u00E1': 'a',  // á => a
      '\u00E2': 'a',  // â => a
      '\u00E3': 'a',  // ã => a
      '\u00E4': 'a',  // ä => a
      '\u00E5': 'a',  // å => a
      '\u00E6': 'a',  // æ => a
      '\u00E7': 'c',  // ç => c
      '\u00E8': 'e',  // è => e
      '\u00E9': 'e',  // é => e
      '\u00EA': 'e',  // ê => e
      '\u00EB': 'e',  // ë => e
      '\u00EC': 'i',  // ì => i
      '\u00ED': 'i',  // í => i
      '\u00EE': 'i',  // î => i
      '\u00EF': 'i',  // ï => i
      '\u0133': 'i',  // ĳ => ij
      '\u00F0': 'd',  // ð => d
      '\u00F1': 'n',  // ñ => n
      '\u00F2': 'o',  // ò => o
      '\u00F3': 'o',  // ó => o
      '\u00F4': 'o',  // ô => o
      '\u00F5': 'o',  // õ => o
      '\u00F6': 'o',  // ö => o
      '\u00F8': 'o',  // ø => o
      '\u0153': 'o',  // œ => o
      '\u00DF': 's',  // ß => ss
      '\u00FE': 't',  // þ => th
      '\u00F9': 'u',  // ù => u
      '\u00FA': 'u',  // ú => u
      '\u00FB': 'u',  // û => u
      '\u00FC': 'u',  // ü => u
      '\u00FD': 'y',  // ý => y
      '\u00FF': 'y'   // ÿ => y
    };
    function removeDiacritics (text) {
      return text.replace(/[^\u0000-\u007E]/g, function(a) {
        return diacriticsMap[a] || a;
      });
    }
    /* eslint-enable */
    function removeQuotes(text) {
      return text.replace(/([a-z])([^a-z \t\r\n_-])([a-z])/g, '$1-$3');
    }
    function escapeRegexp(text) {
      return text.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&');
    }
    function cleanText(text) {
      return removeQuotes(removeDiacritics(text.toLowerCase()));
    }
    function cleanInput(input) {
      input = input.replace(/(^\*(AND|OR)\s+)|(\s+(OR|AND)\*$)/, '');
      input = input.replace(/\s+(AND|OR)\s+/, ' #$1# ');
      input = cleanText(input);
      return escapeRegexp(input);
    }

    // Highlight the keywords.
    function highlight(node) {
      if (!node || node.className === 'highlight') {
        return;
      }
      // Process all the children first.
      if (node.hasChildNodes()) {
        // The node's childNodes will change when hilighting text (new children).
        for (var i = 0, l = node.childNodes.length; i < l; i++) {
          highlight(node.childNodes[i]);
        }
      }
      // Text node, highlight the keywords.
      if (node.nodeType === 3 && node.nodeValue) {
        // First match.
        var result = highlightRegexp.exec(cleanText(node.nodeValue));
        if (result) {
          var matched = result[0];
          var index = result.index;
          var color = matchedColors[matched];

          if (!color) {
            color = matchedColors[matched] = colors[colorIndex++ % colors.length];
          }

          // Create a new tag with for the hilighted keyword.
          var content = document.createTextNode(node.nodeValue.substr(index, matched.length));
          var tag = document.createElement('em');
          tag.appendChild(content);
          tag.className = 'highlight ' + color;

          // Split the text and insert the higlighted text as a new child node.
          var after = node.splitText(index);
          after.nodeValue = after.nodeValue.substr(matched.length);
          node.parentNode.insertBefore(tag, after);
          highlight(after);
        }
      }
    }

    // Search for the keywords.
    this.search = function (node) {
      var text = cleanText(node.textContent || node.innerText);

      // Skip if there is no text.
      if (!text || /^\s+$/.test(text)) {
        return false;
      }

      // Skip if not all keywords can be found.
      for (var i = 0, l = matchRegexps.length; i < l; i++) {
        if (!matchRegexps[i].test(text)) {
          return false;
        }
      }

      // Hightlight keywords.
      highlight(node);

      return true;
    };

    // Remove higlighted text.
    this.remove = function () {
      var tags = document.getElementsByClassName('highlight');
      for (var i = tags.length - 1; i >= 0; i--) {
        var tag = tags[i];
        var parent = tag.parentNode;
        var textNode = document.createTextNode(tag.textContent || tag.innerText);
        parent.replaceChild(textNode, tag);
        parent.normalize();
      }
    };

    // Set the the search keywords.
    this.apply = function (input) {
      input = cleanInput(input);

      // Remove the previous highlighs.
      this.remove();

      // Reset.
      matchedColors = {};
      matchRegexps = [];
      colorIndex = 0;

      // Prepare the search regexps.
      if (input !== '') {
        // Remove the previous hilighting.
        this.remove();

        // Explode AND groups.
        var parts = input.split(' #and# ');
        for (var i = 0, l = parts.length; i < l; i++) {
          matchRegexps.push(new RegExp('(' + parts[i].replace(' #or# ', '|') + ')'));
        }
        // Higlight all keywords.
        highlightRegexp = new RegExp(input.replace(/ #(or|and)# /g, '|'));

        return true;
      }
      return false;
    };
  };
})();
