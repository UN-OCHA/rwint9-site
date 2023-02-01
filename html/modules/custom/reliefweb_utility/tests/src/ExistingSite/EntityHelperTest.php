<?php

namespace Drupal\Tests\reliefweb_utility\ExistingSite;

use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\reliefweb_utility\Helpers\EntityHelper;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Request;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests entity helper.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\EntityHelper
 * @coversDefaultClass \Drupal\reliefweb_utility\Helpers\EntityHelper
 */
class EntityHelperTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $nodes = [
      [
        'type' => 'blog_post',
        'nid' => 9999901,
        'status' => 1,
        'moderation_status' => 'published',
        'title' => 'test blog post 1',
        'author' => 'test',
        'body' => 'test body',
      ],
      [
        'type' => 'blog_post',
        'nid' => 9999902,
        'status' => 1,
        'moderation_status' => 'published',
        'title' => 'test blog post 2',
        'author' => 'test',
        'body' => 'test body',
      ],
    ];

    foreach ($nodes as $data) {
      if (!Node::load($data['nid'])) {
        $this->createNode($data);
      }
    }

  }

  /**
   * @covers ::getEntityFromRequest
   */
  public function testGetEntityFromRequest() {
    $request = $this->createRequestFromRoute('entity.node.canonical', [
      'node' => Node::load(9999901),
    ]);
    $entity = EntityHelper::getEntityFromRequest($request);
    $this->assertEquals($entity?->id(), 9999901);
  }

  /**
   * @covers ::getEntityFromRoute
   */
  public function testGetEntityFromRoute() {
    $request = $this->createRequestFromRoute('entity.node.canonical', [
      'node' => Node::load(9999901),
    ]);

    $request_stack = \Drupal::requestStack();
    while ($request_stack->pop() !== NULL) {
      // Nothing to do.
    }
    $request_stack->push($request);

    $entity = EntityHelper::getEntityFromRoute();
    $this->assertEquals($entity?->id(), 9999901);

    $request = $this->createRequestFromRoute('entity.node.canonical', [
      'node' => Node::load(9999902),
    ]);
    $route_match = RouteMatch::createFromRequest($request);

    $entity = EntityHelper::getEntityFromRoute($route_match);
    $this->assertEquals($entity?->id(), 9999902);
  }

  /**
   * Generate a request for the given route name and parameters.
   *
   * @param string $route_name
   *   Route name.
   * @param array $route_parameters
   *   Route parameters.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   Request.
   */
  private function createRequestFromRoute($route_name, array $route_parameters = []) {
    $route = \Drupal::service('router.route_provider')->getRouteByName($route_name);
    $request = new Request();
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    foreach ($route_parameters as $name => $value) {
      $request->attributes->set($name, $value);
    }
    return $request;
  }

}
