/**
 * @file
 * Attach destination to login link.
 */

(function () {
  var loginLink = document.querySelector('a[href="/user/login"]');
  if (loginLink) {
    loginLink.href += '?destination=' + location.pathname + location.search + location.hash;

    var loginLinks = document.querySelectorAll('a[href="/user/login/hid"]');
    if (loginLinks) {
      var destination = '/';

      if ('URLSearchParams' in window) {
        const searchParams = new URLSearchParams(location.search);
        if (searchParams.has('destination')) {
          destination = searchParams.get('destination');
        }
      }

      loginLinks.forEach(function (el) {
        el.href += '?destination=' + destination;
      });
    }
  }
  else {
    loginLink = document.querySelector('a[href="/user/login/hid"]');
    if (loginLink) {
      loginLink.href += '?destination=' + location.pathname + location.search + location.hash;
    }
  }

}());

