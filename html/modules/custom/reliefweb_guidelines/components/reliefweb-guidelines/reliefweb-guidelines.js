(function () {
  'use strict';

  // Update the mnenu.
  document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementsByTagName('aside')[0];
    var main = document.getElementsByTagName('main')[0];
    var menuLinks = sidebar.querySelectorAll('nav a');

    function setActiveLink(href) {
      for (var i = 0, l = menuLinks.length; i < l; i++) {
        var link = menuLinks[i];
        if (link.className !== 'active' && link.href === href) {
          link.parentNode.previousElementSibling.checked = true;
          link.className = 'active';
          link.focus();
        }
        else if (link.className === 'active' && link.href !== href) {
          link.className = '';
        }
      }
      lazyLoadImages(href.substr(href.indexOf('#') + 1));
    }

    // Lazy load a picture.
    function lazyLoadImages(id) {
      var article = document.getElementById(id);
      if (article) {
        var images = article.getElementsByTagName('IMG');
        for (var i = 0, l = images.length; i < l; i++) {
          var image = images[i];
          if (image.src.indexOf('blank.gif') !== -1) {
            image.src = image.getAttribute('data-src');
          }
        }
      }
    }

    // Sidebar, card link clicks.
    sidebar.addEventListener('mousedown', function (event) {
      if (event.target.tagName === 'A') {
        setActiveLink(event.target.href);
      }
    });

    // Article, card link clicks.
    main.addEventListener('mousedown', function (event) {
      if (event.target.tagName === 'A' && event.target.getAttribute('href').indexOf('/guidelines#') === 0) {
        setActiveLink(event.target.href);
      }
    });

    // Set the active link when the page is loaded.
    setActiveLink(window.location.href);

    // Ensure external links open in a new tab/window.
    var links = main.getElementsByTagName('a');
    for (var i = 0, l = links.length; i < l; i++) {
      var link = links[i];
      var href = link.getAttribute('href');
      if (href && href.indexOf('http') === 0) {
        link.setAttribute('target', '_blank');
      }
    }

    // Search handling.
    var searchForm = sidebar.querySelector('form');
    var searchInput = searchForm.querySelector('input');
    var search = document.getElementById('search');
    var searcher = new window.Searcher();
    var previousInput = '';

    searchForm.addEventListener('submit', function (event) {
      var input = searchInput.value;

      // Check if there is something to do with the search query.
      if (input !== previousInput) {
        var content = document.createElement('div');
        previousInput = input;

        // Search the articles.
        if (searcher.apply(input)) {
          var articles = document.getElementsByTagName('article');

          for (var i = 0, l = articles.length; i < l; i++) {
            var article = articles[i];
            if (article.id !== 'search' && searcher.search(article)) {
              var link = document.createElement('a');
              link.href = '#' + article.id;
              link.innerHTML = article.querySelector('h2').innerHTML;
              link.addEventListener('click', function (event) {
                setActiveLink(this.href);
              });
              content.appendChild(link);
            }
          }
        }

        // Number of articles with the keywords.
        var titleText = 'Found ' + content.childNodes.length + ' entries containing "' + input + '"';
        var title = document.createElement('h2');
        title.appendChild(document.createTextNode(titleText));

        // Clear the search and display the new results.
        while (search.lastChild) {
          search.removeChild(search.lastChild);
        }
        search.appendChild(title);
        search.appendChild(content);
      }

      // Display the search results page.
      window.location.href = '#search';
      event.preventDefault();
    });
  });
})();
