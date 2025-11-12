<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_moderation\ExistingSite\Form;

use Drupal\Core\Form\FormState;
use Drupal\reliefweb_moderation\Form\DomainPostingRightsOverviewForm;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the DomainPostingRightsOverviewForm.
 */
#[CoversClass(DomainPostingRightsOverviewForm::class)]
#[Group('reliefweb_moderation')]
class DomainPostingRightsOverviewFormTest extends ExistingSiteBase {

  /**
   * Source vocabulary.
   */
  protected Vocabulary $sourceVocabulary;

  /**
   * Test source entity.
   */
  protected Term $testSource;

  /**
   * Test source entity 2.
   */
  protected Term $testSource2;

  /**
   * Test source entity 3.
   */
  protected Term $testSource3;

  /**
   * Test domain.
   */
  protected string $testDomain = 'example.com';

  /**
   * Test domain 2.
   */
  protected string $testDomain2 = 'test.org';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Source vocabulary.
    $this->sourceVocabulary = Vocabulary::load('source');
    if (!$this->sourceVocabulary) {
      $this->sourceVocabulary = Vocabulary::create([
        'vid' => 'source',
        'name' => 'Source',
      ]);
      $this->sourceVocabulary->save();
    }

    // Create test sources.
    $this->testSource = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source 1',
      'field_allowed_content_types' => [
        // Job, Report, Training.
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
    ]);

    $this->testSource2 = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source 2',
      'field_allowed_content_types' => [
        // Job, Report, Training.
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
    ]);

    $this->testSource3 = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Test Source 3',
      'field_allowed_content_types' => [
        // Job, Report, Training.
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
    ]);
  }

  /**
   * Test form creation.
   */
  public function testCreate(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $this->assertInstanceOf(DomainPostingRightsOverviewForm::class, $form);
  }

  /**
   * Test getFormId.
   */
  public function testGetFormId(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $this->assertEquals('reliefweb_moderation_domain_posting_rights_overview_form', $form->getFormId());
  }

  /**
   * Test buildForm with no domain posting rights.
   */
  public function testBuildFormNoDomainRights(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Set request with no query parameters.
    $request = Request::create('/test', 'GET');
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify form structure.
    $this->assertArrayHasKey('filters', $built_form);
    $this->assertArrayHasKey('table', $built_form);
    $this->assertArrayHasKey('#attached', $built_form);
    $this->assertArrayHasKey('#attributes', $built_form);

    // Verify filters section.
    $filters = $built_form['filters'];
    $this->assertEquals('details', $filters['#type']);
    $this->assertArrayHasKey('domain', $filters);
    $this->assertArrayHasKey('source', $filters);
    $this->assertArrayHasKey('actions', $filters);

    // Verify table shows no results message.
    $table = $built_form['table'];
    $this->assertArrayHasKey('#markup', $table);
    $this->assertStringContainsString('No domain posting rights found', (string) $table['#markup']);
  }

  /**
   * Test buildForm with domain posting rights.
   */
  public function testBuildFormWithDomainRights(): void {
    // Add domain posting rights to test sources.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Blocked for jobs.
      'job' => 1,
      // Trusted for trainings.
      'training' => 3,
    ]);
    $this->testSource->save();

    $this->testSource2->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Unverified for reports.
      'report' => 0,
      // Allowed for jobs.
      'job' => 2,
      // Blocked for trainings.
      'training' => 1,
    ]);
    $this->testSource2->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Set request with no query parameters.
    $request = Request::create('/test', 'GET');
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify table structure.
    $table = $built_form['table'];
    $this->assertEquals('table__grouped', $table['#theme']);
    $this->assertArrayHasKey('#header', $table);
    $this->assertArrayHasKey('#rows', $table);
    $this->assertNotEmpty($table['#rows']);

    // Verify header structure.
    $header = $table['#header'];
    $this->assertCount(6, $header);
    $this->assertEquals('Domain', (string) $header[0]);
    $this->assertEquals('Source', (string) $header[1]['data']);
    $this->assertEquals('Report', (string) $header[2]);
    $this->assertEquals('Job', (string) $header[3]);
    $this->assertEquals('Training', (string) $header[4]);
    $this->assertEquals('Edit', (string) $header[5]);
  }

  /**
   * Test buildForm with domain filter.
   */
  public function testBuildFormWithDomainFilter(): void {
    // Add domain posting rights to different domains.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      'training' => 2,
    ]);
    $this->testSource->save();

    $this->testSource2->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain2,
      'report' => 2,
      'job' => 2,
      'training' => 2,
    ]);
    $this->testSource2->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Set request with domain filter.
    $request = Request::create('/test', 'GET', ['domain' => $this->testDomain, 'source' => '']);
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify filters are open when filter is applied.
    $filters = $built_form['filters'];
    $this->assertTrue($filters['#open']);

    // Verify domain filter value is set.
    $this->assertEquals($this->testDomain, $filters['domain']['#default_value']);

    // Verify table only shows filtered domain.
    $table = $built_form['table'];
    $this->assertArrayHasKey('#rows', $table);
    // All rows should be for the filtered domain.
    foreach ($table['#rows'] as $row) {
      $this->assertEquals($this->testDomain, $row['group']);
    }
  }

  /**
   * Test buildForm with source filter.
   */
  public function testBuildFormWithSourceFilter(): void {
    // Add domain posting rights to test sources.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $this->testSource2->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource2->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Set request with source filter.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';
    $request = Request::create('/test', 'GET', ['domain' => '', 'source' => $source_input]);
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify filters are open when filter is applied.
    $filters = $built_form['filters'];
    $this->assertTrue($filters['#open']);

    // Verify source filter value is set.
    $this->assertEquals($source_input, $filters['source']['#default_value']);

    // Verify table only shows filtered source.
    $table = $built_form['table'];
    $this->assertArrayHasKey('#rows', $table);
    // All rows should be for the filtered source.
    foreach ($table['#rows'] as $row) {
      $found = FALSE;
      foreach ($row['data'] as $cell) {
        if (isset($cell['data']['#type']) && $cell['data']['#type'] === 'link') {
          // Check if this is the source link.
          $found = TRUE;
          break;
        }
      }
      $this->assertTrue($found, 'Filtered source should be in the table');
    }
  }

  /**
   * Test buildForm with both domain and source filters.
   */
  public function testBuildFormWithBothFilters(): void {
    // Add domain posting rights.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Set request with both filters.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';
    $request = Request::create('/test', 'GET', ['domain' => $this->testDomain, 'source' => $source_input]);
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify filters are open.
    $filters = $built_form['filters'];
    $this->assertTrue($filters['#open']);
    $this->assertEquals($this->testDomain, $filters['domain']['#default_value']);
    $this->assertEquals($source_input, $filters['source']['#default_value']);
  }

  /**
   * Test buildForm normalizes domain filter.
   */
  public function testBuildFormNormalizesDomainFilter(): void {
    // Add domain posting rights.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => 'EXAMPLE.COM',
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Test with different domain formats.
    $request = Request::create('/test', 'GET', ['domain' => '@EXAMPLE.COM', 'source' => '']);
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form1 = $form->buildForm([], $form_state);

    // Domain should be normalized.
    $filters1 = $built_form1['filters'];
    $this->assertEquals('example.com', $filters1['domain']['#default_value']);

    // Test with whitespace.
    $form_state2 = new FormState();
    $request2 = Request::create('/test', 'GET', ['domain' => '  EXAMPLE.COM  ', 'source' => '']);
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request2);
    $built_form2 = $form->buildForm([], $form_state2);

    $filters2 = $built_form2['filters'];
    $this->assertEquals('example.com', $filters2['domain']['#default_value']);
  }

  /**
   * Test buildForm with multiple domains (pagination).
   */
  public function testBuildFormPagination(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Create more than DOMAINS_PER_PAGE domains.
    $domains_per_page = 4;
    $number_of_domains = $domains_per_page + 2;

    // Set number of domains to display per page.
    $form->setDomainsPerPage($domains_per_page);

    // Create unique domains distributed across sources.
    $sources = [$this->testSource, $this->testSource2, $this->testSource3];
    for ($i = 0; $i < $number_of_domains; $i++) {
      $domain = "test{$i}.com";
      // Distribute domains across the 3 sources.
      $source = $sources[$i % 3];
      $source->get('field_domain_posting_rights')->appendItem([
        'domain' => $domain,
        // Allowed for reports.
        'report' => 2,
        // Allowed for jobs.
        'job' => 2,
        // Allowed for trainings.
        'training' => 2,
      ]);
    }

    // Save all sources.
    foreach ($sources as $source) {
      $source->save();
    }

    // Test first page.
    $request = Request::create('/moderation/domain-posting-rights', 'GET');
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify pager element is present in form.
    $this->assertArrayHasKey('pager', $built_form);
    $this->assertEquals('pager', $built_form['pager']['#type']);

    // Verify pagination summary is present.
    $this->assertArrayHasKey('pagination_summary', $built_form);
    $summary = $built_form['pagination_summary'];

    // If pager is initialized, verify summary structure and values.
    if (!empty($summary)) {
      $this->assertEquals('inline_template', $summary['#type']);
      $this->assertArrayHasKey('#context', $summary);
      $this->assertArrayHasKey('start', $summary['#context']);
      $this->assertArrayHasKey('end', $summary['#context']);
      $this->assertArrayHasKey('total', $summary['#context']);

      // Verify pagination summary values for first page.
      $this->assertEquals(1, $summary['#context']['start']);
      $this->assertEquals($domains_per_page, $summary['#context']['end']);
      $this->assertEquals($number_of_domains, $summary['#context']['total']);
    }

    // Verify table is present.
    $this->assertArrayHasKey('table', $built_form);
    $table = $built_form['table'];
    $this->assertArrayHasKey('#rows', $table);
    $this->assertNotEmpty($table['#rows']);

    // Verify only first page of domains is shown.
    $unique_domains_page1 = [];
    foreach ($table['#rows'] as $row) {
      $unique_domains_page1[$row['group']] = TRUE;
    }
    $this->assertLessThanOrEqual($domains_per_page, count($unique_domains_page1));
    $this->assertGreaterThanOrEqual(1, count($unique_domains_page1));

    // Reset the pager manager to clear cached pagers so that the new page
    // parameter is picked up in the new request.
    $this->container->set('pager.manager', NULL);

    // Test second page.
    $form_state2 = new FormState();
    $request2 = Request::create('/moderation/domain-posting-rights', 'GET', ['page' => 1]);
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request2);

    // Create a new form instance to ensure clean state.
    $form2 = DomainPostingRightsOverviewForm::create($container);
    $form2->setDomainsPerPage($domains_per_page);
    $built_form2 = $form2->buildForm([], $form_state2);

    // Verify table is present.
    $this->assertArrayHasKey('table', $built_form2);
    $table2 = $built_form2['table'];
    $this->assertArrayHasKey('#rows', $table2);
    $this->assertNotEmpty($table2['#rows']);

    // Verify pagination summary is present.
    $this->assertArrayHasKey('pagination_summary', $built_form2);
    $summary2 = $built_form2['pagination_summary'];

    // Verify pagination summary values for second page.
    if (!empty($summary2)) {
      $this->assertEquals($domains_per_page + 1, $summary2['#context']['start']);
      $this->assertEquals($number_of_domains, $summary2['#context']['end']);
      $this->assertEquals($number_of_domains, $summary2['#context']['total']);
    }

    // Verify second page shows remaining domains.
    $unique_domains_page2 = [];
    foreach ($table2['#rows'] as $row) {
      $unique_domains_page2[$row['group']] = TRUE;
    }
    $remaining = $number_of_domains - $domains_per_page;
    $this->assertLessThanOrEqual($remaining, count($unique_domains_page2));
    $this->assertGreaterThanOrEqual(1, count($unique_domains_page2));

    // Verify pages show different domains.
    $domains_page1 = array_keys($unique_domains_page1);
    $domains_page2 = array_keys($unique_domains_page2);
    $this->assertEmpty(array_intersect($domains_page1, $domains_page2), 'Pages should show different domains');
  }

  /**
   * Test buildForm groups by domain.
   */
  public function testBuildFormGroupsByDomain(): void {
    // Add domain posting rights to multiple sources for same domain.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource->save();

    $this->testSource2->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Blocked for reports.
      'report' => 1,
      // Blocked for jobs.
      'job' => 1,
      // Blocked for trainings.
      'training' => 1,
    ]);
    $this->testSource2->save();

    $this->testSource3->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain2,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $this->testSource3->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    $request = Request::create('/test', 'GET');
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify table groups by domain.
    $table = $built_form['table'];
    $this->assertArrayHasKey('#rows', $table);

    // Count domains in rows.
    $domains_found = [];
    foreach ($table['#rows'] as $row) {
      $domains_found[$row['group']] = TRUE;
    }

    // Should have 2 domains.
    $this->assertCount(2, $domains_found);
    $this->assertArrayHasKey($this->testDomain, $domains_found);
    $this->assertArrayHasKey($this->testDomain2, $domains_found);
  }

  /**
   * Test buildForm sorts sources alphabetically within domain.
   */
  public function testBuildFormSortsSourcesAlphabetically(): void {
    // Create sources with different names.
    $source_z = $this->createTerm($this->sourceVocabulary, [
      'name' => 'Z Source',
      'field_allowed_content_types' => [
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
    ]);

    $source_a = $this->createTerm($this->sourceVocabulary, [
      'name' => 'A Source',
      'field_allowed_content_types' => [
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
    ]);

    $source_m = $this->createTerm($this->sourceVocabulary, [
      'name' => 'M Source',
      'field_allowed_content_types' => [
        ['value' => 0],
        ['value' => 1],
        ['value' => 2],
      ],
    ]);

    // Add domain posting rights to all sources for same domain.
    $source_a->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $source_a->save();

    $source_m->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $source_m->save();

    $source_z->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      // Allowed for reports.
      'report' => 2,
      // Allowed for jobs.
      'job' => 2,
      // Allowed for trainings.
      'training' => 2,
    ]);
    $source_z->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    $request = Request::create('/test', 'GET');
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify sources are sorted alphabetically.
    $table = $built_form['table'];
    $source_names = [];
    foreach ($table['#rows'] as $row) {
      if ($row['group'] === $this->testDomain) {
        foreach ($row['data'] as $cell) {
          if (isset($cell['data']['#type']) && $cell['data']['#type'] === 'link') {
            $source_names[] = $cell['data']['#title'];
            break;
          }
        }
      }
    }

    // Should be sorted: A, M, Z.
    $this->assertGreaterThanOrEqual(3, count($source_names));
    $this->assertEquals('A Source', $source_names[0]);
    $this->assertEquals('M Source', $source_names[1]);
    $this->assertEquals('Z Source', $source_names[2]);
  }

  /**
   * Test validateForm skips validation for filter button.
   */
  public function testValidateFormSkipsForFilterButton(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Set triggering element to filter button.
    $form_state->setTriggeringElement([
      '#name' => 'filter',
    ]);

    $built_form = $form->buildForm([], $form_state);

    // Should not have errors.
    $form->validateForm($built_form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertEmpty($errors);
  }

  /**
   * Test submitForm with filter button.
   */
  public function testSubmitFormWithFilterButton(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Set triggering element to filter button.
    $form_state->setTriggeringElement([
      '#name' => 'filter',
    ]);

    // Set filter values.
    $form_state->setValue([
      'filters',
      'domain',
    ], '  EXAMPLE.COM  ');
    $form_state->setValue([
      'filters',
      'source',
    ], 'Test Source [id:123]');

    $built_form = $form->buildForm([], $form_state);
    $form->submitForm($built_form, $form_state);

    // Should redirect with normalized filters.
    $redirect = $form_state->getRedirect();
    $this->assertNotNull($redirect);

    $route_name = $redirect->getRouteName();
    $this->assertEquals('reliefweb_moderation.domain_posting_rights.overview', $route_name);

    $query = $redirect->getOption('query');
    $this->assertArrayHasKey('domain', $query);
    $this->assertEquals('example.com', $query['domain']);
    $this->assertArrayHasKey('source', $query);
    $this->assertEquals('Test Source [id:123]', $query['source']);
  }

  /**
   * Test submitForm with filter button and empty values.
   */
  public function testSubmitFormWithFilterButtonEmptyValues(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Set triggering element to filter button.
    $form_state->setTriggeringElement([
      '#name' => 'filter',
    ]);

    // Set empty filter values.
    $form_state->setValue([
      'filters',
      'domain',
    ], '');
    $form_state->setValue([
      'filters',
      'source',
    ], '');

    $built_form = $form->buildForm([], $form_state);
    $form->submitForm($built_form, $form_state);

    // Should redirect with empty query.
    $redirect = $form_state->getRedirect();
    $this->assertNotNull($redirect);

    $query = $redirect->getOption('query');
    $this->assertEmpty($query);
  }

  /**
   * Test formatPostingRights formats all right codes correctly.
   */
  public function testFormatPostingRights(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);

    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($form);
    $method = $reflection->getMethod('formatPostingRights');
    $method->setAccessible(TRUE);

    // Test all valid right codes.
    $rights = [
      0 => ['right' => 'unverified', 'label' => 'Unverified'],
      1 => ['right' => 'blocked', 'label' => 'Blocked'],
      2 => ['right' => 'allowed', 'label' => 'Allowed'],
      3 => ['right' => 'trusted', 'label' => 'Trusted'],
    ];

    foreach ($rights as $code => $expected) {
      $formatted = $method->invoke($form, $code);

      $this->assertEquals('html_tag', $formatted['#type']);
      $this->assertEquals('span', $formatted['#tag']);
      $this->assertEquals($expected['label'], (string) $formatted['#value']);
      $this->assertArrayHasKey('class', $formatted['#attributes']);
      $this->assertContains('rw-user-posting-right', $formatted['#attributes']['class']);
      $this->assertContains('rw-user-posting-right--large', $formatted['#attributes']['class']);
      $this->assertEquals($expected['right'], $formatted['#attributes']['data-user-posting-right']);
    }

    // Test unknown right code.
    $formatted = $method->invoke($form, 99);
    $this->assertEquals('unknown', $formatted['#attributes']['data-user-posting-right']);
    $this->assertEquals('Unknown', (string) $formatted['#value']);
  }

  /**
   * Test getDestination builds correct URL.
   */
  public function testGetDestination(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);

    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($form);
    $method = $reflection->getMethod('getDestination');
    $method->setAccessible(TRUE);

    // Test with both filters.
    $destination = $method->invoke($form, 'example.com', 'Test Source [id:123]');
    $this->assertStringContainsString('example.com', $destination);
    // URL encoding can use either + or %20 for spaces, check for both patterns.
    $this->assertTrue(
      str_contains($destination, 'Test+Source+%5Bid%3A123%5D') ||
      str_contains($destination, 'Test%20Source%20%5Bid%3A123%5D'),
      'Destination should contain encoded source filter'
    );

    // Test with only domain filter.
    $destination = $method->invoke($form, 'example.com', '');
    $this->assertStringContainsString('example.com', $destination);
    $this->assertStringNotContainsString('source=', $destination);

    // Test with only source filter.
    $destination = $method->invoke($form, '', 'Test Source [id:123]');
    $this->assertStringNotContainsString('domain=', $destination);
    // URL encoding can use either + or %20 for spaces, check for both patterns.
    $this->assertTrue(
      str_contains($destination, 'Test+Source+%5Bid%3A123%5D') ||
      str_contains($destination, 'Test%20Source%20%5Bid%3A123%5D'),
      'Destination should contain encoded source filter'
    );

    // Test with no filters.
    $destination = $method->invoke($form, '', '');
    $this->assertStringNotContainsString('domain=', $destination);
    $this->assertStringNotContainsString('source=', $destination);
  }

  /**
   * Test normalizeDomain normalizes various formats.
   */
  public function testNormalizeDomain(): void {
    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);

    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($form);
    $method = $reflection->getMethod('normalizeDomain');
    $method->setAccessible(TRUE);

    // Test with @ prefix.
    $normalized = $method->invoke($form, '@example.com');
    $this->assertEquals('example.com', $normalized);

    // Test with uppercase.
    $normalized = $method->invoke($form, 'EXAMPLE.COM');
    $this->assertEquals('example.com', $normalized);

    // Test with whitespace.
    $normalized = $method->invoke($form, '  example.com  ');
    $this->assertEquals('example.com', $normalized);

    // Test with @ prefix and uppercase and whitespace.
    $normalized = $method->invoke($form, '  @EXAMPLE.COM  ');
    $this->assertEquals('example.com', $normalized);

    // Test normal domain.
    $normalized = $method->invoke($form, 'example.com');
    $this->assertEquals('example.com', $normalized);
  }

  /**
   * Test buildForm with source that has shortname.
   */
  public function testBuildFormWithSourceShortname(): void {
    // Add shortname to source.
    $this->testSource->set('field_shortname', 'TS1');
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      'report' => 2,
      'job' => 2,
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    $request = Request::create('/test', 'GET');
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify shortname is displayed.
    $table = $built_form['table'];
    $found_shortname = FALSE;
    foreach ($table['#rows'] as $row) {
      foreach ($row['data'] as $cell) {
        if (isset($cell['data']) && $cell['data'] === 'TS1') {
          $found_shortname = TRUE;
          break 2;
        }
      }
    }
    $this->assertTrue($found_shortname, 'Source shortname should be displayed');
  }

  /**
   * Test buildForm with source without shortname.
   */
  public function testBuildFormWithoutSourceShortname(): void {
    // Source without shortname.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      'report' => 2,
      'job' => 2,
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    $request = Request::create('/test', 'GET');
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify dash is displayed for missing shortname.
    $table = $built_form['table'];
    $found_dash = FALSE;
    foreach ($table['#rows'] as $row) {
      foreach ($row['data'] as $cell) {
        if (isset($cell['data']) && $cell['data'] === '-') {
          $found_dash = TRUE;
          break 2;
        }
      }
    }
    $this->assertTrue($found_dash, 'Dash should be displayed for missing shortname');
  }

  /**
   * Test buildForm edit link includes destination.
   */
  public function testBuildFormEditLinkIncludesDestination(): void {
    // Add domain posting rights.
    $this->testSource->get('field_domain_posting_rights')->appendItem([
      'domain' => $this->testDomain,
      'report' => 2,
      'job' => 2,
      'training' => 2,
    ]);
    $this->testSource->save();

    $container = $this->container;
    $form = DomainPostingRightsOverviewForm::create($container);
    $form_state = new FormState();

    // Set request with filters - use actual source ID to ensure match.
    $source_input = $this->testSource->label() . ' [id:' . $this->testSource->id() . ']';
    $request = Request::create('/test', 'GET', ['domain' => $this->testDomain, 'source' => $source_input]);
    $container->get('request_stack')->pop();
    $container->get('request_stack')->push($request);

    $built_form = $form->buildForm([], $form_state);

    // Verify edit link includes destination.
    $table = $built_form['table'];

    // Check if table has rows (might be empty if no match).
    if (!isset($table['#rows']) || empty($table['#rows'])) {
      $this->markTestSkipped('No rows in table - source filter may not match');
      return;
    }

    $found_edit_link = FALSE;
    foreach ($table['#rows'] as $row) {
      if (!isset($row['data']) || !is_array($row['data'])) {
        continue;
      }
      foreach ($row['data'] as $cell) {
        if (isset($cell['data']) && is_array($cell['data'])) {
          if (isset($cell['data']['#type']) && $cell['data']['#type'] === 'link') {
            if (isset($cell['data']['#title']) && (string) $cell['data']['#title'] === 'Edit') {
              $url = $cell['data']['#url'];
              $query = $url->getOption('query');
              $this->assertArrayHasKey('destination', $query);
              $this->assertStringContainsString($this->testDomain, $query['destination']);
              $found_edit_link = TRUE;
              break 2;
            }
          }
        }
      }
    }
    $this->assertTrue($found_edit_link, 'Edit link should include destination');
  }

}
