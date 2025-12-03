# Schema.org JSON-LD Documentation

This document describes the schema.org JSON-LD structured data implementation for ReliefWeb content types and taxonomy terms.

## Overview

The ReliefWeb site implements schema.org structured data using JSON-LD format for the following entity types:

- **Reports** - Documents, news articles, maps, and other content
- **Jobs** - Job postings
- **Training** - Training courses and educational events
- **Sources** - Organizations
- **Countries** - Geographic locations
- **Disasters** - Disaster events

All schemas use the entity permalink (non-aliased URL) as the `@id` identifier, and the canonical (aliased) URL as the `url` property.

---

## Reports

Reports use different schema types based on their content format:

- **Report** (default) - Standard reports and documents
- **NewsArticle** - News articles (content format ID: 8)
- **Map** - Maps (content format ID: 12)
- **CreativeWork** - Other creative works (when specified via `field_json_schema`)

### Common Properties

All report variants include:

- `@id` - Entity permalink URL
- `url` - Canonical URL
- `dateCreated` - Creation timestamp (ISO 8601)
- `dateModified` - Last modification timestamp (ISO 8601)
- `datePublished` - Original publication date (YYYY-MM-DD) or creation date as fallback
- `isAccessibleForFree` - Always `true`
- `sdPublisher` - ReliefWeb organization
- `abstract` - Summarized content from body field
- `inLanguage` - Language codes from `field_language`
- `keywords` - Combined from themes and disaster types
- `author` - Source organizations (from `field_source`)
- `publisher` - Source organizations (from `field_source`)
- `spatialCoverage` - Countries (from `field_country`)
- `isBasedOn` - Origin URL (from `field_origin_notes`, if valid URL)

### Report Variants

#### Standard Report

```json
{
  "@context": "https://schema.org",
  "@type": "Report",
  "@id": "https://reliefweb.int/report/12345",
  "url": "https://reliefweb.int/report/example-report",
  "headline": "Example Report Title",
  "dateCreated": "2024-01-15T10:30:00+00:00",
  "dateModified": "2024-01-20T14:45:00+00:00",
  "datePublished": "2024-01-10",
  "isAccessibleForFree": true,
  "sdPublisher": {
    "@type": "Organization",
    "name": "ReliefWeb"
  },
  "abstract": "Summary of the report content...",
  "keywords": ["Theme 1", "Theme 2", "Disaster Type"],
  "inLanguage": ["en", "fr"],
  "author": [
    {
      "@type": "Organization",
      "@id": "https://reliefweb.int/source/123",
      "name": "Source Organization"
    }
  ],
  "publisher": [
    {
      "@type": "Organization",
      "@id": "https://reliefweb.int/source/123",
      "name": "Source Organization"
    }
  ],
  "spatialCoverage": [
    {
      "@type": "Country",
      "@id": "https://reliefweb.int/country/456",
      "name": "Country Name"
    }
  ]
}
```

#### News Article

Uses `NewsArticle` type with `headline` property:

```json
{
  "@context": "https://schema.org",
  "@type": "NewsArticle",
  "@id": "https://reliefweb.int/report/12345",
  "url": "https://reliefweb.int/report/example-news",
  "headline": "Example News Article Title",
  "datePublished": "2024-01-10",
  "isAccessibleForFree": true,
  "sdPublisher": {
    "@type": "Organization",
    "name": "ReliefWeb"
  }
}
```

#### Map

Uses `Map` type with `name` property:

```json
{
  "@context": "https://schema.org",
  "@type": "Map",
  "@id": "https://reliefweb.int/report/12345",
  "url": "https://reliefweb.int/report/example-map",
  "name": "Example Map Title",
  "datePublished": "2024-01-10",
  "isAccessibleForFree": true
}
```

---

## Jobs

Jobs use the `JobPosting` schema type.

### Properties

- `@id` - Entity permalink URL
- `url` - Canonical URL
- `title` - Job title
- `datePosted` - Creation timestamp (ISO 8601)
- `employmentType` - Job type from `field_job_type` (default: "Job")
- `validThrough` - Job closing date from `field_job_closing_date`
- `description` - Summarized content from body field
- `keywords` - Combined from themes and career categories
- `hiringOrganization` - Source organization (from `field_source`)
- `jobLocation` - Country (from `field_country`) or `jobLocationType` if no country specified
- `experienceRequirements` - Occupational experience requirements (months) from `field_job_experience`

### Experience Requirements Mapping

- ID 258 (0-2 years) → 0 months
- ID 259 (3-4 years) → 36 months
- ID 260 (5-9 years) → 60 months
- ID 261 (10+ years) → 120 months

### Example

```json
{
  "@context": "https://schema.org",
  "@type": "JobPosting",
  "@id": "https://reliefweb.int/job/12345",
  "url": "https://reliefweb.int/job/example-job",
  "title": "Example Job Title",
  "datePosted": "2024-01-15T10:30:00+00:00",
  "employmentType": "Full-time",
  "validThrough": "2024-02-15",
  "description": "Summary of the job posting...",
  "keywords": ["Theme 1", "Career Category"],
  "hiringOrganization": {
    "@type": "Organization",
    "@id": "https://reliefweb.int/source/123",
    "name": "Hiring Organization"
  },
  "jobLocation": {
    "@type": "Country",
    "@id": "https://reliefweb.int/country/456",
    "name": "Country Name"
  },
  "experienceRequirements": {
    "@type": "OccupationalExperienceRequirements",
    "monthsOfExperience": 36
  }
}
```

### Remote/Roster/Roving Jobs

When no country is specified, `jobLocationType` is set to "Remote, roster, roving, or location to be determined":

```json
{
  "@context": "https://schema.org",
  "@type": "JobPosting",
  "jobLocationType": "Remote, roster, roving, or location to be determined"
}
```

---

## Training

Training content uses different schema types based on training type and date availability:

- **Course** - Academic degrees/courses (training type ID: 4610) or when no dates are available
- **CourseInstance** - Course instances with dates (extends Event)
- **EducationEvent** - Training events (Call for Papers ID: 4608, Conference/Lecture ID: 21006, Training/Workshop ID: 4609)

### Common Properties

All training variants include:

- `@id` - Entity permalink URL
- `url` - Canonical URL
- `name` - Training title
- `description` - Summarized content from body field
- `keywords` - Combined from training type, career categories, themes, and "permanent" (if no dates)
- `inLanguage` - Language codes from `field_training_language`
- `provider` / `organizer` - Source organizations (provider for courses, organizer for events)
- `sameAs` - Event URL from `field_link` (if valid URL)

### Training Variants

#### Course (No Dates / Permanent)

For courses without dates or permanent training:

```json
{
  "@context": "https://schema.org",
  "@type": "Course",
  "@id": "https://reliefweb.int/training/12345",
  "url": "https://reliefweb.int/training/example-course",
  "name": "Example Course Title",
  "dateCreated": "2024-01-15T10:30:00+00:00",
  "dateModified": "2024-01-20T14:45:00+00:00",
  "description": "Summary of the course...",
  "keywords": ["Course Type", "permanent", "Theme 1"],
  "inLanguage": ["en"],
  "provider": [
    {
      "@type": "Organization",
      "@id": "https://reliefweb.int/source/123",
      "name": "Training Provider"
    }
  ],
  "isAccessibleForFree": true
}
```

#### Course with Instance (With Dates)

For courses with training dates, a `CourseInstance` is created:

```json
{
  "@context": "https://schema.org",
  "@type": "Course",
  "@id": "https://reliefweb.int/training/12345",
  "url": "https://reliefweb.int/training/example-course",
  "name": "Example Course Title",
  "hasCourseInstance": {
    "@type": "CourseInstance",
    "startDate": "2024-03-01",
    "endDate": "2024-03-05",
    "eventAttendanceMode": "https://schema.org/OnlineEventAttendanceMode",
    "courseMode": ["online"],
    "location": [
      {
        "@type": "Country",
        "@id": "https://reliefweb.int/country/456",
        "name": "Country Name"
      }
    ],
    "offers": {
      "@type": "Offer",
      "validThrough": "2024-02-15",
      "description": "Fee information"
    }
  },
  "isAccessibleForFree": false
}
```

#### Education Event

For training events (workshops, conferences, etc.):

```json
{
  "@context": "https://schema.org",
  "@type": "EducationEvent",
  "@id": "https://reliefweb.int/training/12345",
  "url": "https://reliefweb.int/training/example-event",
  "name": "Example Training Event",
  "startDate": "2024-03-01",
  "endDate": "2024-03-05",
  "eventAttendanceMode": "https://schema.org/MixedEventAttendanceMode",
  "location": [
    {
      "@type": "Country",
      "@id": "https://reliefweb.int/country/456",
      "name": "Country Name"
    }
  ],
  "organizer": [
    {
      "@type": "Organization",
      "@id": "https://reliefweb.int/source/123",
      "name": "Event Organizer"
    }
  ],
  "offers": {
    "@type": "Offer",
    "validThrough": "2024-02-15"
  },
  "isAccessibleForFree": true
}
```

### Attendance Modes

- **Online only** (format ID: 4607) → `OnlineEventAttendanceMode`
- **Onsite only** (format ID: 4606) → `OfflineEventAttendanceMode`
- **Both online and onsite** → `MixedEventAttendanceMode`

### Cost Information

- `isAccessibleForFree` - Set to `true` if cost is "free", `false` if "fee-based"
- `offers.description` - Fee information from `field_fee_information` (for fee-based training)
- `offers.validThrough` - Registration deadline from `field_registration_deadline`

---

## Sources

Sources (organizations) use the `Organization` schema type, wrapped in a `ProfilePage` to represent the ReliefWeb profile page.

### Properties

- `@id` (ProfilePage) - Canonical URL
- `url` (ProfilePage) - Canonical URL
- `mainEntity` - Organization entity with:
  - `@id` - Entity permalink URL
  - `name` - Organization name
  - `url` - Homepage from `field_homepage`
  - `alternateName` - Shortname, longname, aliases, Spanish name
  - `location` - Headquarter country (from `field_country`)
  - `sameAs` - Social media links

### Example

```json
{
  "@context": "https://schema.org",
  "@type": "ProfilePage",
  "@id": "https://reliefweb.int/source/example-org",
  "url": "https://reliefweb.int/source/example-org",
  "mainEntity": {
    "@type": "Organization",
    "@id": "https://reliefweb.int/source/123",
    "name": "Example Organization",
    "url": "https://example.org",
    "alternateName": [
      "Example Org",
      "Example Organization Long Name",
      "Alias 1",
      "Alias 2"
    ],
    "location": {
      "@type": "Country",
      "@id": "https://reliefweb.int/country/456",
      "name": "Country Name"
    },
    "sameAs": [
      "https://twitter.com/example",
      "https://facebook.com/example"
    ]
  }
}
```

---

## Countries

Countries use the `Country` schema type (via custom `CountrySchema` class), wrapped in a `CollectionPage` to represent the ReliefWeb country page.

### Properties

- `@id` (CollectionPage) - Canonical URL
- `url` (CollectionPage) - Canonical URL
- `name` (CollectionPage) - Country name
- `about` - Country entity with:
  - `@id` - Entity permalink URL
  - `name` - Country name
  - `identifier` - ISO 3166-1 alpha-3 code (as PropertyValue)
  - `alternateName` - Shortname, longname, aliases
  - `geo` - Geographic coordinates (from `field_location`)

### Example

```json
{
  "@context": "https://schema.org",
  "@type": "CollectionPage",
  "@id": "https://reliefweb.int/country/example-country",
  "url": "https://reliefweb.int/country/example-country",
  "name": "Example Country",
  "about": {
    "@type": "Country",
    "@id": "https://reliefweb.int/country/456",
    "name": "Example Country",
    "identifier": {
      "@type": "PropertyValue",
      "propertyID": "ISO 3166-1 alpha-3",
      "value": "ABC"
    },
    "alternateName": [
      "Short Name",
      "Long Name",
      "Alias 1"
    ],
    "geo": {
      "@type": "GeoCoordinates",
      "latitude": 40.7128,
      "longitude": -74.0060
    }
  }
}
```

---

## Disasters

Disasters use the `Event` schema type (via custom `DisasterSchema` class) since there is no specific disaster type in schema.org.

### Properties

- `@id` - Entity permalink URL
- `url` - Canonical URL
- `name` - Disaster name
- `startDate` - Disaster date from `field_disaster_date`
- `description` - Summarized content from description field
- `keywords` - Combined from:
  - "Disaster" (always included)
  - Moderation status
  - Primary disaster type (from `field_primary_disaster_type`)
  - Disaster types (from `field_disaster_type`)
- `identifier` - GLIDE numbers (as PropertyValue array) from `field_glide` and `field_glide_related`
- `location` - Affected countries (from `field_primary_country` and `field_country`)

### Example

```json
{
  "@context": "https://schema.org",
  "@type": "Event",
  "@id": "https://reliefweb.int/disaster/12345",
  "url": "https://reliefweb.int/disaster/example-disaster",
  "name": "Example Disaster",
  "startDate": "2024-01-10",
  "description": "Summary of the disaster...",
  "keywords": [
    "Disaster",
    "Active",
    "Earthquake",
    "Natural Disaster"
  ],
  "identifier": [
    {
      "@type": "PropertyValue",
      "propertyID": "https://glidenumber.net/",
      "value": "GL-2024-000001-ABC"
    }
  ],
  "location": [
    {
      "@type": "Country",
      "@id": "https://reliefweb.int/country/456",
      "name": "Affected Country"
    }
  ]
}
```

---

## Common Patterns

### Entity References

When referencing other entities (sources, countries, disasters), the schema uses:

- `@id` - Entity permalink URL (non-aliased)
- `@type` - Appropriate schema type (Organization, Country, Event)
- `name` - Entity label

### URL Handling

- **Permalink URL** (`@id`) - Non-aliased, stable URL for entity identification
- **Canonical URL** (`url`) - Aliased, user-friendly URL for the web page

### Content Summarization

Content is summarized using the `summarizeContent()` method to avoid duplicating full content and improve page load performance. The summary length is configurable per entity type via state configuration.

### Language Codes

Language codes are extracted from referenced language taxonomy terms via the `field_language_code` field. The "Other" language code (`ot`) is excluded.

---

## Implementation Notes

- All schemas use the [Spatie Schema.org PHP library](https://github.com/spatie/schema-org)
- Custom schema classes (`CountrySchema`, `DisasterSchema`) are used to work around library limitations for setting multiple identifiers
- The `identifier()` method in the Spatie library is forcefully converted to `@id`, so custom classes use `setProperty()` for additional identifiers
- All dates use ISO 8601 format (YYYY-MM-DD for dates, full ISO 8601 with timezone for timestamps)
