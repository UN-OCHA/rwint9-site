<?php

/**
 * @file
 * Simple script to generate icons sprites.
 */

/**
 * Get the icon definitions.
 *
 * @return array
 *   Associative array keyed by category and with each element containing the
 *   following information:
 *   - source: path to the directory with the icons for the category
 *   - colors: associative array with color ids as keys and colors as values
 *     as css values (ex: #fff, var(--my-color), rgb(0,0,0))
 *   - sizes: array of sizes in pixels
 */
function get_settings() {
  $settings = json_decode(file_get_contents('rw-icons.json'), TRUE);
  if (isset($settings['version']) && is_file($settings['version'])) {
    $settings['version'] = trim(file_get_contents($settings['version']));
  }
  else {
    $settings['version'] = time();
  }
  return $settings;
}

/**
 * Get the file icons for the category.
 *
 * @param array $definition
 *   Icon definition for the category.
 *
 * @return array
 *   List of icon files.
 */
function get_icon_files(array $definition) {
  if (empty($definition['source']) || !is_dir($definition['source'])) {
    return [];
  }

  $files = glob($definition['source'] . '/*.svg');
  if (empty($files)) {
    return [];
  }

  $exclude = !empty($definition['exclude']) ? array_flip($definition['exclude']) : [];
  $include = !empty($definition['include']) ? array_flip($definition['include']) : [];

  $result = [];
  foreach ($files as $path) {
    $id = basename($path, '.svg');
    if (isset($exclude[$id])) {
      continue;
    }
    elseif (empty($include) || isset($include[$id])) {
      $result[] = $path;
    }
  }

  return $result;
}

/**
 * Extract the CSS variables from a CSS style file.
 *
 * @param array $settings
 *   The settings with the colors notably.
 *
 * @return array
 *   Associative array of css variable/value pairs.
 */
function extract_css_variables(array $settings) {
  if (empty($settings['style']) || !file_exists($settings['style'])) {
    return [];
  }

  $variables = [];

  $style = file_get_contents($settings['style']);
  $pattern = '#(?<variable>--[a-z0-9-]+\s*:\s*[^;}]+)#';

  if (preg_match_all($pattern, $style, $matches)) {
    foreach ($matches['variable'] as $variable) {
      [$name, $value] = explode(':', $variable, 2);
      $variables[trim($name)] = trim($value);
    }
  }

  // Resolve variables extending other variables.
  // We're only interested in basic colors so normally there is no fancy stuff.
  foreach ($variables as $name => $value) {
    $variables[$name] = preg_replace_callback('#var\((--[a-z0-9-]+)\)#', function ($matches) use ($variables) {
      return $variables[$matches[1]] ?? '';
    }, $value);
  }

  return $variables;
}

/**
 * Generate the style element with the classes with the colors.
 *
 * @param \DomElement $svg
 *   The SVG dom element.
 * @param array $settings
 *   The settings with the colors notably.
 */
function generate_style(\DomElement $svg, array $settings) {
  if (empty($settings['icons']) || empty($settings['colors'])) {
    return;
  }

  // Get the list of color classes actually in use.
  $classes = [];
  foreach ($settings['icons'] as $definition) {
    foreach ($definition['sizes'] ?? [] as $color_classes) {
      foreach ($color_classes as $class) {
        $classes[$class] = '';
      }
    }
  }

  // Populate the the color classes, replacing css variables if any.
  $variables = extract_css_variables($settings);
  foreach ($settings['colors'] as $class => $color) {
    if (isset($classes[$class])) {
      if (substr($color, 0, 2) !== '--' || isset($variables[$color])) {
        $color = $variables[$color] ?? $color;
        $classes[$class] = ".$class { fill: $color; }";
      }
    }
  }

  // Only add the style element if there are color classes.
  $classes = array_filter($classes);
  if (!empty($classes)) {
    $style = $svg->ownerDocument->createElement('style');
    $style->textContent = "\n    " . implode("\n    ", $classes) . "\n  ";
    $svg->appendChild($style);
  }
}

/**
 * Create a symbol element with the given id and view box.
 *
 * @param \DomDocument $dom
 *   SVG dom document.
 * @param string $id
 *   Symbol id.
 * @param string $view_box
 *   ViewBox attribute.
 *
 * @return \DomElement
 *   Symbol element.
 */
function create_symbol_element(\DomDocument $dom, $id, $view_box = '') {
  $symbol = $dom->createElement('symbol');
  $symbol->setAttribute('id', $id);
  if (!empty($view_box)) {
    $symbol->setAttribute('viewBox', $view_box);
  }
  return $symbol;
}

/**
 * Add a use element with the given properties to the parent.
 *
 * @param \DomDocument $dom
 *   SVG dom document.
 * @param string $id
 *   Id of the symbol to use.
 * @param int $width
 *   Width of the use element (to scale the symbol).
 * @param int $height
 *   Height of the use element (to scale the symbol).
 * @param int $x
 *   X position of the use element.
 * @param int $y
 *   Y position of the use element.
 * @param string $class
 *   The color class of the use element.
 *
 * @return \DomElement
 *   The use element.
 */
function create_use_element(\DomDocument $dom, $id, $width, $height, $x = 0, $y = 0, $class = '') {
  $use = $dom->createElement('use');
  $use->setAttribute('xlink:href', '#' . $id);
  $use->setAttribute('width', $width);
  $use->setAttribute('height', $height);
  if ($x !== 0) {
    $use->setAttribute('x', $x);
  }
  if ($y !== 0) {
    $use->setAttribute('y', $y);
  }
  if (!empty($class)) {
    $use->setAttribute('class', $class);
  }
  return $use;
}

/**
 * Get a SVG synbol element from the given SVG icon.
 *
 * Note: the SVG icon is assumed to a 48x48 icon.
 *
 * @param string $category
 *   Icon category used to prefix the id.
 * @param string $path
 *   Path to the SVG icon.
 * @param \DomElement $parent
 *   Parent to which add the icon symbol element.
 *
 * @return \DomNode
 *   Symbol element.
 */
function parse_icon($category, $path, \DomElement $parent) {
  $id = $category . '--' . basename($path, '.svg');

  // Load the svg icon.
  $dom = new \DomDocument();
  $dom->preserveWhiteSpace = FALSE;
  $dom->formatOutput = TRUE;
  $dom->load($path);

  // Get the SVG element.
  $svg = $dom->getElementsByTagName('svg')->item(0);

  // Create a symbol element and copy the viewbox attribute.
  $symbol = create_symbol_element($dom, $id, $svg->getAttribute('viewBox'));

  // Copy the content of the svg element.
  while ($svg->firstChild !== NULL) {
    $symbol->appendChild($svg->firstChild);
  }

  $symbol = $parent->ownerDocument->importNode($symbol, TRUE);
  $parent->appendChild($symbol);

  // Clean the imported symbol's content.
  foreach ($symbol->childNodes as $child) {
    $child->removeAttributeNS('http://www.w3.org/2000/svg', 'default');
    $child->removeAttributeNS('http://www.w3.org/2000/svg', '');
  }

  return $id;
}

/**
 * Generate the CSS file with the variables for the different icons.
 *
 * @param array $settings
 *   The global settings.
 * @param array $icons
 *   List of icon ids grouped by icon categories.
 */
function generate_css(array $settings, array $icons) {
  $path = $settings['path'] ?? '../rw-icons';
  $version = $settings['version'] ?? time();
  $categories = [];

  // Generate the CSS file.
  $base = 'var(--rw-icons-sprite) @x @y no-repeat;';
  $x = 0;
  foreach ($icons as $category => $ids) {
    $sizes = $settings['icons'][$category]['sizes'];

    foreach ($sizes as $size => $colors) {
      foreach ($colors as $color) {
        // Add a variable on the position x for the color and size of the
        // category that can be used to easily change the color of an icon.
        $categories[$category][] = strtr("--rw-icons--$category--$size--$color--x: @x;", [
          '@x' => $x === 0 ? 0 : (-$x) . 'px',
        ]);

        // Add variables for each icon in the current color and size.
        foreach ($ids as $index => $id) {
          $y = $index * $size;
          $categories[$id][] = strtr("--rw-icons--$id--$size--$color: $base", [
            '@x' => $x === 0 ? 0 : (-$x) . 'px',
            '@y' => $y === 0 ? 0 : (-$y) . 'px',
          ]);
        }

        $x += $size;
      }
    }
  }

  // Group the variables per icon id.
  $variables = [
    '--rw-icons-sprite: url("' . $path . '/img/rw-icons-sprite.svg?v=' . $version . '");',
  ];
  foreach ($categories as $items) {
    foreach ($items as $item) {
      $variables[] = $item;
    }
  }

  $content = ":root {\n  " . implode("\n  ", $variables) . "\n}\n";
  file_put_contents('rw-icons.css', $content);
}

/**
 * Generate the SVG sprite and the corresponding CSS file.
 */
function generate_sprite() {
  $settings = get_settings();

  // Skip if there are no icons definitions.
  if (empty($settings['icons'])) {
    echo 'Missing icons in settings.' . PHP_EOL;
    return;
  }

  $dom = new \DomDocument();
  $dom->preserveWhiteSpace = FALSE;
  $dom->formatOutput = TRUE;

  // Create the SVG container element.
  $svg = $dom->createElement('svg');
  $svg->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
  $svg->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');

  // Add a style element with the color classes.
  generate_style($svg, $settings);

  // Container for the actual content of the sprite (the final "use" elements).
  $content = $dom->createDocumentFragment();

  // Track the x position of the group of icons and the height of the tallest
  // group.
  $position = 0;
  $max_height = 0;

  // Keep track of the icons that were added so we can generate the CSS file.
  $icons = [];

  // Generate the symbols and populate the content.
  foreach ($settings['icons'] as $category => $definition) {
    // Skip if there are no sizes or colors for the category.
    if (empty($definition['source']) || empty($definition['sizes'])) {
      continue;
    }

    // Get the icons for the category, skip if empty.
    $icon_files = get_icon_files($definition);

    // Number of icons.
    $count = count($icon_files);

    // Id of the icon group.
    $group_id = 'group-' . $category;

    // Generate the group for the category.
    $group = create_symbol_element($dom, $group_id, '0 0 48 ' . ($count * 48));

    // Parse the icons for the category.
    foreach ($icon_files as $index => $path) {
      $id = parse_icon($category, $path, $svg);
      $use = create_use_element($dom, $id, 48, 48, 0, 48 * $index);
      $group->appendChild($use);

      // Store the icon information.
      $icons[$category][] = $id;
    }

    // Add the icon group to the SVG.
    $group = $svg->appendChild($group);

    // Generate the groups in the different sizes.
    foreach ($definition['sizes'] as $size => $classes) {
      $id = $group_id . '--' . $size;
      $height = $size * $count;
      $width = $size * count($classes);

      // Create the symbol for the given size.
      $symbol = create_symbol_element($dom, $id, "0 0 $width $height");
      $symbol = $svg->appendChild($symbol);

      // Add the icon group symbol with the proper size and color class.
      foreach ($classes as $index => $class) {
        $use = create_use_element($dom, $group_id, $size, $height, $size * $index, 0, $class);
        $symbol->appendChild($use);
      }

      // Create an element using this symbol.
      $use = create_use_element($dom, $id, $width, $height, $position);
      $content->appendChild($use);

      // Update the x position.
      $position += $width;
      $max_height = max($max_height, $height);
    }
  }

  // Generate the icon file.
  if ($content->hasChildNodes()) {
    $svg->appendChild($content);
    $svg->setAttribute('viewBox', "0 0 $position $max_height");
    $svg->setAttribute('width', $position);
    $svg->setAttribute('height', $max_height);

    // Save the generate sprite. We are using saveXML, passing the SVG
    // element so that the doctype is not added as it is not needed.
    file_put_contents('img/rw-icons-sprite.svg', $dom->saveXML($svg));

    echo 'Icon sprite successfully generated.' . PHP_EOL;
  }
  else {
    echo 'No icon content to generate.' . PHP_EOL;
  }

  // Generate the CSS.
  generate_css($settings, $icons);
}

/**
 * Generate the individual icons from the given sprite.
 *
 * Note: this is a convenience function to facilitate importing the icons
 * from the Drupal 7 site.
 *
 * @param string $sprite
 *   Path to the sprite file.
 * @param string $category
 *   Category of the icons. A matching directory will be created.
 */
function generate_individual_icons($sprite, $category) {
  if (!file_exists($sprite)) {
    echo 'Sprite file not found.' . PHP_EOL;
    return;
  }

  // Create the icon directory if doesn't exist.
  $directory = 'img/icons/' . $category;
  @mkdir($directory, 0777, TRUE);

  // Load the sprite.
  $dom = new \DomDocument();
  $dom->preserveWhiteSpace = FALSE;
  $dom->formatOutput = TRUE;
  $dom->load($sprite);

  $count = 0;
  foreach ($dom->getElementsByTagName('symbol') as $symbol) {
    if ($symbol->hasAttribute('data-source')) {
      $count++;
      $id = $symbol->getAttribute('id');

      // Create the SVG container element.
      $svg = $dom->createElement('svg');
      $svg->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
      $svg->setAttribute('viewBox', $symbol->getAttribute('viewBox'));
      $svg->setAttribute('id', $id);
      $svg->setAttribute('data-source', $symbol->getAttribute('data-source'));

      // Copy the content of the svg element.
      while ($symbol->firstChild !== NULL) {
        $svg->appendChild($symbol->firstChild);
      }

      // Save the individual file.
      file_put_contents($directory . '/' . $id . '.svg', $dom->saveXML($svg));
    }
  }

  echo 'Created ' . $count . ' SVG icons in ' . $directory . '.' . PHP_EOL;
}

// Change to the directory of the script.
chdir(dirname(__FILE__));

// Parse the command line options.
$options = getopt('s:c:');

// Generate individual SVG files from the given sprite with the given category.
if (!empty($options['s']) && !empty($options['c'])) {
  generate_individual_icons($options['s'], $options['c']);
}
// Generate the icon sprite from the individual SVG files and settings.
else {
  generate_sprite();
}
