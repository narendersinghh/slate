<?php
declare(strict_types=1);

namespace Slate;

/**
 * Schema.org structured data per content type (SEO › Schema by type).
 *
 * Each public post type has a schema type (stored in seo_types[type][schema], as before) and, optionally, a mapping
 * of schema properties to built-in values, custom fields, taxonomies or fixed text (option "seo_schema_map":
 * [post type => [schema type => [property path => source]]]).
 *
 * Sources: "" not used, "@title", "@excerpt", "@description", "@content", "@image", "@url", "@published", "@modified",
 * "@author", "@site", "field:{name}", "tax:{taxonomy}", "={fixed text}".
 *
 * Compatibility: a schema type that existed before mappings (see LEGACY) keeps its exact previous output until a
 * mapping is saved for it. New schema types use smart suggestions until a mapping is saved.
 */
final class StructuredData
{
    /** Schema types available before per-type mapping. Without a saved mapping they render exactly as before. */
    public const LEGACY = ['WebPage', 'Article', 'BlogPosting', 'NewsArticle', 'Product', 'Service', 'Event', 'Recipe', 'FAQPage',
        'AboutPage', 'ContactPage', 'CollectionPage', 'None'];

    /** Types that describe the page itself (no separate entity node). */
    public const PAGE_TYPES = ['WebPage', 'AboutPage', 'ContactPage', 'FAQPage', 'CollectionPage', 'None'];

    public const ARTICLE_TYPES = ['Article', 'BlogPosting', 'NewsArticle'];

    /** Type of a nested object, by property name (last segment) or full path. */
    private const NESTED = [
        'offers' => 'Offer', 'aggregateRating' => 'AggregateRating', 'brand' => 'Brand', 'author' => 'Person', 'director' => 'Person',
        'performer' => 'Person', 'provider' => 'Organization', 'organizer' => 'Organization', 'publisher' => 'Organization',
        'hiringOrganization' => 'Organization', 'worksFor' => 'Organization', 'location' => 'Place', 'jobLocation' => 'Place',
        'birthPlace' => 'Place', 'address' => 'PostalAddress', 'geo' => 'GeoCoordinates', 'nutrition' => 'NutritionInformation',
        'baseSalary' => 'MonetaryAmount', 'baseSalary.value' => 'QuantitativeValue', 'applicantLocationRequirements' => 'Country',
        'hasCourseInstance' => 'CourseInstance',
    ];

    /** Words too generic to match inside longer field names ("city_name" is not a name). */
    private const LOOSE_STOP = ['name', 'title', 'type', 'date', 'time', 'image', 'description', 'status', 'mode', 'code', 'link', 'price', 'rating', 'value', 'author', 'location', 'category'];

    /** Built-in value sources. */
    public const BUILTINS = [
        '@title' => 'Title', '@excerpt' => 'Excerpt', '@description' => 'Meta description', '@content' => 'Content',
        '@image' => 'Featured image', '@url' => 'Item URL', '@published' => 'Publish date', '@modified' => 'Last updated',
        '@author' => 'Author name', '@site' => 'Site name',
    ];

    /** Shortcut for the property catalog: [label, kind, hints, required by Google]. */
    private static function p(string $label, string $kind = 'text', array $hints = [], bool $req = false): array
    {
        return ['label' => $label, 'kind' => $kind, 'hints' => $hints, 'req' => $req];
    }

    private static function addressProps(string $prefix, string $label = 'Address'): array
    {
        return [
            "$prefix.streetAddress" => self::p("$label: street", 'text', ['street_address', 'street', 'address', 'address_line', 'address1', 'addr']),
            "$prefix.addressLocality" => self::p("$label: city", 'text', ['city', 'town', 'locality', 'city_name']),
            "$prefix.addressRegion" => self::p("$label: state or region", 'text', ['state', 'region', 'province', 'county']),
            "$prefix.postalCode" => self::p("$label: postal code", 'text', ['postal_code', 'postcode', 'zip', 'zipcode', 'pincode', 'pin_code']),
            "$prefix.addressCountry" => self::p("$label: country", 'text', ['country', 'country_code']),
        ];
    }

    /**
     * Mappable properties of a schema type (dotted paths for nested objects).
     * @return array<string, array{label:string, kind:string, hints:array, req:bool}>
     */
    public static function props(string $type): array
    {
        $name = ['name' => self::p('Name', 'text', ['@title'], true)];
        $desc = ['description' => self::p('Description', 'text', ['summary', 'about', 'bio', '@description'])];
        $image = ['image' => self::p('Image', 'image', ['photo', 'picture', 'cover', '@image'])];
        $url = ['url' => self::p('URL', 'url', ['@url'])];
        $rating = [
            'aggregateRating.ratingValue' => self::p('Rating value', 'number', ['rating', 'average_rating', 'avg_rating', 'stars', 'score']),
            'aggregateRating.reviewCount' => self::p('Number of reviews', 'number', ['review_count', 'reviews', 'reviews_count', 'num_reviews', 'rating_count', 'ratings']),
        ];
        $offer = static fn(bool $req = false) => [
            'offers.price' => self::p('Price', 'number', ['price', 'cost', 'fee', 'amount'], $req),
            'offers.priceCurrency' => self::p('Price currency (e.g. USD)', 'currency', ['currency', 'price_currency']),
            'offers.availability' => self::p('Availability (e.g. InStock)', 'enum', ['availability', 'stock', 'stock_status']),
            'offers.url' => self::p('Offer URL', 'url', ['buy_url', 'booking_url', 'ticket_url', 'tickets', 'purchase_url']),
        ];
        $article = [
            'headline' => self::p('Headline', 'text', ['@title'], true),
            'description' => self::p('Description', 'text', ['@description']),
            'image' => self::p('Image', 'image', ['@image'], true),
            'datePublished' => self::p('Date published', 'datetime', ['@published'], true),
            'dateModified' => self::p('Date modified', 'datetime', ['@modified']),
            'author.name' => self::p('Author', 'text', ['@author'], true),
            'articleSection' => self::p('Section', 'text', ['section']),
            'keywords' => self::p('Keywords', 'list', ['keywords', 'tags']),
        ];
        return match ($type) {
            'Article', 'BlogPosting', 'NewsArticle' => $article,
            'Product' => $name + $desc + ['image' => self::p('Image', 'image', ['photo', 'picture', 'gallery', '@image'])] + [
                'sku' => self::p('SKU', 'text', ['sku', 'product_code', 'item_code']),
                'gtin' => self::p('GTIN / EAN / UPC', 'text', ['gtin', 'ean', 'upc', 'barcode']),
                'mpn' => self::p('MPN', 'text', ['mpn', 'model', 'model_number']),
                'brand.name' => self::p('Brand', 'text', ['brand', 'manufacturer', 'make']),
                'category' => self::p('Category', 'text', ['category', 'product_category']),
            ] + $offer(true) + $rating,
            'Service' => $name + $desc + $image + [
                'serviceType' => self::p('Service type', 'text', ['service_type', 'type', 'category']),
                'areaServed' => self::p('Area served', 'text', ['area_served', 'service_area', 'area', 'city', 'location']),
                'provider.name' => self::p('Provider', 'text', ['provider', 'company', 'business', '@site']),
            ] + array_slice($offer(), 0, 2, true),
            'Event' => $name + $desc + $image + [
                'startDate' => self::p('Start date', 'datetime', ['start_date', 'starts', 'start', 'event_date', 'date', 'start_time'], true),
                'endDate' => self::p('End date', 'datetime', ['end_date', 'ends', 'end', 'end_time']),
                'eventStatus' => self::p('Status (e.g. EventScheduled)', 'enum', ['event_status', 'status']),
                'eventAttendanceMode' => self::p('Attendance (e.g. OfflineEventAttendanceMode)', 'enum', ['attendance_mode', 'attendance', 'mode']),
                'location.name' => self::p('Venue name', 'text', ['venue', 'venue_name', 'location', 'place'], true),
            ] + self::addressProps('location.address', 'Venue address') + $offer() + [
                'organizer.name' => self::p('Organizer', 'text', ['organizer', 'organiser', 'host', 'organizer_name']),
                'organizer.url' => self::p('Organizer URL', 'url', ['organizer_url', 'organizer_website']),
                'performer.name' => self::p('Performer', 'text', ['performer', 'artist', 'speaker', 'performers']),
            ],
            'Recipe' => ['name' => self::p('Name', 'text', ['@title'], true), 'image' => self::p('Image', 'image', ['@image'], true)] + $desc + [
                'author.name' => self::p('Author', 'text', ['chef', '@author']),
                'datePublished' => self::p('Date published', 'date', ['@published']),
                'prepTime' => self::p('Prep time (minutes or ISO 8601)', 'duration', ['prep_time', 'preparation_time', 'prep']),
                'cookTime' => self::p('Cook time', 'duration', ['cook_time', 'cooking_time', 'cook']),
                'totalTime' => self::p('Total time', 'duration', ['total_time', 'time', 'duration']),
                'recipeYield' => self::p('Yield (servings)', 'text', ['servings', 'yield', 'serves', 'recipe_yield']),
                'recipeCategory' => self::p('Category', 'text', ['recipe_category', 'course', 'meal', 'category']),
                'recipeCuisine' => self::p('Cuisine', 'text', ['cuisine', 'recipe_cuisine']),
                'recipeIngredient' => self::p('Ingredients (one per line)', 'list', ['ingredients', 'recipe_ingredients', 'ingredient']),
                'recipeInstructions' => self::p('Instructions (one step per line)', 'list', ['instructions', 'steps', 'method', 'directions']),
                'nutrition.calories' => self::p('Calories', 'text', ['calories', 'kcal', 'energy']),
                'keywords' => self::p('Keywords', 'list', ['keywords', 'tags']),
            ] + $rating,
            'Person' => $name + $desc + $image + [
                'url' => self::p('URL', 'url', ['@url']),
                'jobTitle' => self::p('Job title', 'text', ['job_title', 'designation', 'position', 'role', 'title', 'occupation', 'profession']),
                'worksFor.name' => self::p('Works for', 'text', ['company', 'employer', 'organization', 'organisation', 'works_for']),
                'birthDate' => self::p('Birth date', 'date', ['birth_date', 'dob', 'date_of_birth', 'birthday', 'born']),
                'birthPlace.name' => self::p('Birth place', 'text', ['birth_place', 'place_of_birth', 'hometown']),
                'deathDate' => self::p('Death date', 'date', ['death_date', 'date_of_death', 'died']),
                'gender' => self::p('Gender', 'text', ['gender', 'sex']),
                'nationality' => self::p('Nationality', 'text', ['nationality', 'citizenship']),
                'email' => self::p('Email', 'text', ['email', 'email_address', 'mail']),
                'telephone' => self::p('Phone', 'text', ['phone', 'telephone', 'mobile', 'tel', 'phone_number', 'contact_number']),
                'address.addressLocality' => self::p('City', 'text', ['city', 'town', 'location']),
                'sameAs' => self::p('Profiles (sameAs, one URL per line)', 'urls', ['same_as', 'social', 'social_links', 'website', 'links', 'wikipedia']),
            ],
            'Organization', 'LocalBusiness' => ($type === 'LocalBusiness'
                    ? ['@type' => self::p('More specific type (e.g. Restaurant, Dentist)', 'subtype', ['business_type', 'schema_type'])] : [])
                + $name + $desc + $image + [
                'logo' => self::p('Logo', 'image', ['logo', 'brand_logo']),
                'url' => self::p('Website', 'url', ['website', 'web', 'homepage', 'site', 'url', '@url']),
                'telephone' => self::p('Phone', 'text', ['phone', 'telephone', 'mobile', 'tel', 'phone_number', 'contact_number'], $type === 'LocalBusiness'),
                'email' => self::p('Email', 'text', ['email', 'email_address', 'mail']),
            ] + self::addressProps('address') + ($type === 'LocalBusiness' ? [
                'geo.latitude' => self::p('Latitude', 'number', ['latitude', 'lat']),
                'geo.longitude' => self::p('Longitude', 'number', ['longitude', 'lng', 'lon', 'long']),
                'priceRange' => self::p('Price range (e.g. $$)', 'text', ['price_range', 'price_level', 'budget', 'cost_range']),
                'openingHours' => self::p('Opening hours (e.g. Mo-Fr 09:00-17:00, one per line)', 'list', ['opening_hours', 'hours', 'business_hours', 'timings', 'open_hours']),
                'servesCuisine' => self::p('Cuisine (restaurants)', 'text', ['cuisine', 'serves_cuisine']),
                'hasMap' => self::p('Map URL', 'url', ['map', 'map_url', 'google_map', 'maps_link']),
            ] + $rating : [
                'foundingDate' => self::p('Founding date', 'date', ['founding_date', 'founded', 'established', 'since', 'year_founded']),
            ]) + [
                'sameAs' => self::p('Profiles (sameAs, one URL per line)', 'urls', ['same_as', 'social', 'social_links', 'facebook', 'instagram', 'linkedin']),
            ],
            'Place' => $name + $desc + $image + [
                'telephone' => self::p('Phone', 'text', ['phone', 'telephone', 'tel']),
            ] + self::addressProps('address') + [
                'geo.latitude' => self::p('Latitude', 'number', ['latitude', 'lat']),
                'geo.longitude' => self::p('Longitude', 'number', ['longitude', 'lng', 'lon', 'long']),
                'hasMap' => self::p('Map URL', 'url', ['map', 'map_url', 'google_map']),
            ],
            'Book' => $name + $desc + $image + [
                'author.name' => self::p('Author', 'text', ['author', 'writer', 'book_author', 'authors'], true),
                'isbn' => self::p('ISBN', 'text', ['isbn', 'isbn13', 'isbn10']),
                'bookFormat' => self::p('Format (e.g. Hardcover, EBook)', 'enum', ['format', 'book_format', 'binding']),
                'numberOfPages' => self::p('Number of pages', 'number', ['pages', 'number_of_pages', 'page_count']),
                'publisher.name' => self::p('Publisher', 'text', ['publisher']),
                'datePublished' => self::p('Date published', 'date', ['publication_date', 'published', 'release_date', 'year']),
                'inLanguage' => self::p('Language', 'text', ['language', 'lang']),
                'bookEdition' => self::p('Edition', 'text', ['edition']),
            ] + $rating,
            'Course' => $name + ['description' => self::p('Description', 'text', ['summary', 'about', '@description'], true)] + $image + [
                'provider.name' => self::p('Provider', 'text', ['provider', 'institute', 'institution', 'school', 'university', '@site'], true),
                'courseCode' => self::p('Course code', 'text', ['course_code', 'code']),
                'educationalLevel' => self::p('Level', 'text', ['level', 'difficulty', 'educational_level']),
                'inLanguage' => self::p('Language', 'text', ['language', 'lang']),
                'timeRequired' => self::p('Duration', 'duration', ['duration', 'course_duration', 'length', 'time_required']),
                'hasCourseInstance.courseMode' => self::p('Mode (Online, Onsite, Blended)', 'text', ['mode', 'course_mode', 'delivery']),
                'hasCourseInstance.courseWorkload' => self::p('Workload (ISO 8601, e.g. PT2H)', 'duration', ['workload']),
                'offers.category' => self::p('Price category (Free, Paid, Subscription)', 'text', ['price_category', 'pricing']),
            ] + array_slice($offer(), 0, 2, true) + $rating,
            'JobPosting' => [
                'title' => self::p('Job title', 'text', ['job_title', '@title'], true),
                'description' => self::p('Description (HTML allowed)', 'html', ['job_description', '@content'], true),
                'datePosted' => self::p('Date posted', 'date', ['date_posted', 'posted', '@published'], true),
                'validThrough' => self::p('Valid through', 'datetime', ['valid_through', 'deadline', 'last_date', 'closing_date', 'expiry', 'expires', 'apply_by']),
                'employmentType' => self::p('Employment type (e.g. FULL_TIME)', 'upper', ['employment_type', 'job_type', 'type']),
                'hiringOrganization.name' => self::p('Company', 'text', ['company', 'company_name', 'employer', 'organization', 'organisation', '@site'], true),
                'hiringOrganization.sameAs' => self::p('Company website', 'url', ['company_website', 'company_url', 'employer_website', 'website']),
                'hiringOrganization.logo' => self::p('Company logo', 'image', ['company_logo', 'logo']),
            ] + self::addressProps('jobLocation.address', 'Job location') + [
                'jobLocationType' => self::p('Remote job (TELECOMMUTE)', 'upper', ['job_location_type', 'remote', 'work_mode']),
                'applicantLocationRequirements.name' => self::p('Remote: country of applicants', 'text', ['applicant_country', 'eligible_country']),
                'baseSalary.currency' => self::p('Salary currency', 'currency', ['salary_currency', 'currency']),
                'baseSalary.value.value' => self::p('Salary', 'number', ['salary', 'pay', 'wage', 'compensation']),
                'baseSalary.value.minValue' => self::p('Salary minimum', 'number', ['salary_min', 'min_salary', 'salary_from']),
                'baseSalary.value.maxValue' => self::p('Salary maximum', 'number', ['salary_max', 'max_salary', 'salary_to']),
                'baseSalary.value.unitText' => self::p('Salary per (HOUR, DAY, WEEK, MONTH, YEAR)', 'upper', ['salary_unit', 'pay_period', 'salary_period']),
                'directApply' => self::p('Direct apply on this page', 'bool', ['direct_apply']),
            ],
            'Movie' => ['name' => self::p('Name', 'text', ['@title'], true), 'image' => self::p('Image', 'image', ['poster', '@image'], true)] + $desc + [
                'dateCreated' => self::p('Release date', 'date', ['release_date', 'released', 'year', 'date_created']),
                'director.name' => self::p('Director', 'text', ['director', 'directed_by']),
                'duration' => self::p('Duration', 'duration', ['duration', 'runtime', 'length']),
                'genre' => self::p('Genre', 'list', ['genre', 'genres']),
                'contentRating' => self::p('Content rating', 'text', ['content_rating', 'certificate', 'rated', 'certification']),
            ] + $rating,
            'CreativeWork' => $name + $desc + $image + $url + [
                'author.name' => self::p('Author / creator', 'text', ['author', 'creator', 'artist', '@author']),
                'dateCreated' => self::p('Date created', 'date', ['date_created', 'created', 'year']),
                'datePublished' => self::p('Date published', 'date', ['@published']),
                'genre' => self::p('Genre', 'list', ['genre', 'genres']),
                'keywords' => self::p('Keywords', 'list', ['keywords', 'tags']),
                'inLanguage' => self::p('Language', 'text', ['language', 'lang']),
            ],
            default => [],
        };
    }

    /** Can this schema type be mapped (an entity node, not the page itself)? */
    public static function mappable(string $type): bool
    {
        return !in_array($type, self::PAGE_TYPES, true) && self::props($type) !== [];
    }

    // ------------------------------------------------------------------ settings

    public static function maps(): array
    {
        return (array)(Options::get('seo_schema_map') ?: []);
    }

    /** Saved mapping of a post type for a schema type, or null. */
    public static function savedMap(string $postType, string $schema): ?array
    {
        $m = self::maps()[$postType][$schema] ?? null;
        return is_array($m) ? $m : null;
    }

    /** Effective mapping: saved values, with suggestions for properties the mapping does not know yet. */
    public static function map(string $postType, string $schema): array
    {
        $saved = self::savedMap($postType, $schema);
        $suggest = self::suggest($postType, $schema);
        return $saved === null ? $suggest : array_intersect_key($saved + $suggest, $suggest);
    }

    /** Archive schema for a taxonomy: "collection" (default) or "itemlist". */
    public static function taxMode(string $tax): string
    {
        return (string)((Options::get('seo_schema_tax') ?: [])[$tax] ?? 'collection');
    }

    /** Default share image for a post type (media id), or 0. */
    public static function typeImage(string $postType): int
    {
        return (int)((Options::get('seo_og_type_images') ?: [])[$postType] ?? 0);
    }

    /** Value sources for a post type: [group label => [source => label]]. */
    public static function sources(string $postType): array
    {
        $fields = [];
        foreach (ContentModel::fieldsFor('post', $postType) as $name => $f) {
            if (in_array($f['type'] ?? '', ['tab', 'repeater', 'form', 'code'], true)) {
                continue;
            }
            $fields['field:' . $name] = ($f['label'] ?? $name) . ' (' . $name . ')';
        }
        $taxes = [];
        foreach (ContentModel::taxonomiesFor($postType) as $slug => $t) {
            $taxes['tax:' . $slug] = $t['plural'] ?? $slug;
        }
        return array_filter(['Built-in' => self::BUILTINS, 'Custom fields' => $fields, 'Taxonomies' => $taxes]);
    }

    private static function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    /** Suggested source for every property, from field names and types. */
    public static function suggest(string $postType, string $schema): array
    {
        $fields = [];
        foreach (ContentModel::fieldsFor('post', $postType) as $name => $f) {
            if (!in_array($f['type'] ?? '', ['tab', 'repeater', 'form', 'code'], true)) {
                $fields[$name] = $f;
            }
        }
        $taxes = ContentModel::taxonomiesFor($postType);
        $out = [];
        $used = [];
        foreach (self::props($schema) as $path => $def) {
            $last = substr((string)strrchr('.' . $path, '.'), 1);
            $hints = array_merge([$path === '@type' ? 'business_type' : $last], $def['hints']);
            $out[$path] = self::pick($hints, $fields, $taxes, $def['kind'], $used);
            if (str_starts_with($out[$path], 'field:')) {
                $used[$out[$path]] = true;
            }
        }
        return $out;
    }

    private static function pick(array $hints, array $fields, array $taxes, string $kind, array $used): string
    {
        // 1. Exact name match (birth_date -> birthDate), 2. name contains the hint (business_phone -> phone),
        // 3. a taxonomy with that name, 4. a field of a telling type, 5. the built-in default.
        foreach ([1, 2] as $pass) {
            foreach ($hints as $h) {
                if ($h[0] === '@') {
                    continue;
                }
                $nh = self::norm($h);
                foreach ($fields as $name => $f) {
                    $nn = self::norm($name);
                    $nl = self::norm((string)($f['label'] ?? ''));
                    $hit = $pass === 1 ? ($nn === $nh || $nl === $nh)
                        : (strlen($nh) >= 4 && !in_array($nh, self::LOOSE_STOP, true) && (str_contains($nn, $nh) || str_contains($nl, $nh)));
                    if ($hit && !isset($used['field:' . $name]) && self::kindFits($kind, (string)($f['type'] ?? 'text'))) {
                        return 'field:' . $name;
                    }
                }
            }
        }
        foreach ($hints as $h) {
            if ($h[0] === '@') {
                continue;
            }
            foreach ($taxes as $slug => $t) {
                if (self::norm($h) === self::norm($slug) || self::norm($h) === self::norm((string)($t['singular'] ?? '')) || self::norm($h) === self::norm((string)($t['plural'] ?? ''))) {
                    return 'tax:' . $slug;
                }
            }
        }
        $byType = ['email' => 'email', 'tel' => 'telephone'];
        foreach ($fields as $name => $f) {
            $t = $byType[$f['type'] ?? ''] ?? null;
            if ($t && in_array(self::norm($t), array_map([self::class, 'norm'], $hints), true) && !isset($used['field:' . $name])) {
                return 'field:' . $name;
            }
        }
        foreach ($hints as $h) {
            if ($h[0] === '@') {
                return $h;
            }
        }
        return '';
    }

    /** Whether a field type can feed a property kind. */
    private static function kindFits(string $kind, string $fieldType): bool
    {
        return match ($kind) {
            'image' => in_array($fieldType, ['image', 'gallery', 'url', 'text'], true),
            'date', 'datetime' => in_array($fieldType, ['date', 'datetime', 'text'], true),
            'number' => in_array($fieldType, ['number', 'text', 'select', 'radio'], true),
            'url', 'urls' => in_array($fieldType, ['url', 'text', 'textarea'], true),
            default => !in_array($fieldType, ['image', 'gallery', 'file'], true),
        };
    }

    // ------------------------------------------------------------------ output

    /**
     * Entity node for a post, or null when the legacy builder in Seo::schema() should be used
     * (a pre-existing schema type without a saved mapping, so the output stays exactly as before).
     */
    public static function node(string $type, array $post, string $canonical, string $entityId): ?array
    {
        if (!self::mappable($type)) {
            return null;
        }
        $saved = self::savedMap((string)$post['type'], $type);
        if ($saved === null && in_array($type, self::LEGACY, true)) {
            return null;
        }
        $map = self::map((string)$post['type'], $type);
        $props = self::props($type);
        $node = ['@type' => $type, '@id' => $canonical . '#' . strtolower($type), 'mainEntityOfPage' => ['@id' => $canonical . '#webpage']];
        if (in_array($type, self::ARTICLE_TYPES, true)) {
            $node['publisher'] = ['@id' => $entityId];
        }
        $fieldDefs = ContentModel::fieldsFor('post', (string)$post['type']);
        foreach ($map as $path => $source) {
            if ($source === '' || !isset($props[$path])) {
                continue;
            }
            $value = self::format(self::resolve((string)$source, $post, $fieldDefs, $props[$path]['kind']), $props[$path]['kind'], $path);
            if ($value === null) {
                continue;
            }
            if ($path === 'headline' && is_string($value)) {
                $value = mb_substr($value, 0, 110); // Google's headline limit
            }
            if ($path === '@type') {
                $node['@type'] = $value;
                continue;
            }
            self::setPath($node, $path, $value);
        }
        return self::validate(self::clean($node));
    }

    /** Raw value of a source for a post. */
    public static function resolve(string $source, array $post, array $fieldDefs, string $kind = 'text'): mixed
    {
        if ($source === '') {
            return null;
        }
        if ($source[0] === '=') {
            return substr($source, 1);
        }
        $utc = static fn(?string $d) => $d ? gmdate('c', (int)strtotime($d . ' UTC')) : null;
        switch ($source) {
            case '@title':
                return (string)$post['title'];
            case '@excerpt':
                return Seo::excerpt($post, 300);
            case '@description':
                return Seo::$ctx['kind'] === 'single' && (int)(Seo::$ctx['post']['id'] ?? 0) === (int)$post['id']
                    ? Seo::description() : (($post['seo']['description'] ?? '') ?: Seo::excerpt($post, 158));
            case '@content':
                return $kind === 'html' ? trim(Seo::stripCodes((string)$post['content'])) : str_limit(Seo::stripCodes((string)$post['content']), 5000);
            case '@image':
                if (Seo::$ctx['kind'] === 'single' && (int)(Seo::$ctx['post']['id'] ?? 0) === (int)$post['id']) {
                    return Seo::image()['url'] ?? null;
                }
                $id = (int)($post['seo']['og_image'] ?? 0) ?: (int)($post['featured_media_id'] ?? 0);
                $m = $id ? Media::find($id) : null;
                return $m ? site_url('content/uploads/' . $m['path']) : null;
            case '@url':
                return Posts::permalink($post, true);
            case '@published':
                return $utc($post['published_at'] ?? null);
            case '@modified':
                return $utc($post['updated_at'] ?? null);
            case '@author':
                $a = !empty($post['author_id']) ? DB::row('SELECT display_name, username FROM {{users}} WHERE id = :id', ['id' => (int)$post['author_id']]) : null;
                return ($a['display_name'] ?? '') ?: ($a['username'] ?? '') ?: (string)Options::get('site_title');
            case '@site':
                return (string)Options::get('site_title');
        }
        if (str_starts_with($source, 'tax:')) {
            $tax = substr($source, 4);
            $terms = $post['terms'][$tax] ?? (Terms::forPosts([(int)$post['id']])[(int)$post['id']][$tax] ?? []);
            return array_values(array_map(static fn($t) => (string)$t['name'], $terms));
        }
        if (str_starts_with($source, 'field:')) {
            $name = substr($source, 6);
            $v = $post['fields'][$name] ?? null;
            $f = $fieldDefs[$name] ?? ['type' => 'text'];
            return self::fieldValue($v, $f);
        }
        return null;
    }

    private static function fieldValue(mixed $v, array $f): mixed
    {
        if ($v === null || $v === '' || $v === []) {
            return null;
        }
        $mediaUrl = static function ($id): ?string {
            $m = Media::find((int)$id);
            return $m ? site_url('content/uploads/' . $m['path']) : null;
        };
        switch ($f['type'] ?? 'text') {
            case 'image':
            case 'file':
                return $mediaUrl($v);
            case 'gallery':
                return array_values(array_filter(array_map($mediaUrl, (array)$v)));
            case 'relation':
                Posts::prime(array_map('intval', (array)$v));
                return array_values(array_filter(array_map(static fn($id) => Posts::find((int)$id)['title'] ?? null, (array)$v)));
            case 'taxonomy':
                return array_values(array_filter(array_map(static fn($id) => Terms::find((int)$id)['name'] ?? null, (array)$v)));
            case 'datetime':
                // Stored in the site's time zone.
                try {
                    return (new \DateTimeImmutable((string)$v, new \DateTimeZone((string)Options::get('timezone', 'UTC'))))->format('c');
                } catch (\Throwable) {
                    return null;
                }
            case 'select':
            case 'radio':
            case 'checkbox':
                $labels = self::choiceLabels($f['options'] ?? []);
                $map = static fn($x) => is_scalar($x) ? ($labels[(string)$x] ?? (string)$x) : '';
                return is_array($v) ? array_map($map, $v) : $map($v);
            default:
                return $v;
        }
    }

    /** "value : Label" option lines. */
    private static function choiceLabels(array|string $options): array
    {
        $out = [];
        foreach (is_array($options) ? $options : (preg_split('/\R/', $options) ?: []) as $line) {
            if (!is_string($line)) {
                continue;
            }
            $parts = array_map('trim', explode(':', $line, 2));
            if ($parts[0] !== '') {
                $out[$parts[0]] = $parts[1] ?? $parts[0];
            }
        }
        return $out;
    }

    private static function plain(mixed $v): string
    {
        $s = is_array($v) ? implode(', ', array_filter(array_map(static fn($x) => is_scalar($x) ? (string)$x : '', $v), 'strlen')) : (is_scalar($v) ? (string)$v : '');
        $s = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', ' ', $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    private static function absUrl(mixed $v): ?string
    {
        $s = trim(is_array($v) ? (string)(reset($v) ?: '') : (is_scalar($v) ? (string)$v : ''));
        if ($s === '') {
            return null;
        }
        if (str_starts_with($s, '//')) {
            $s = 'https:' . $s;
        } elseif (str_starts_with($s, '/')) {
            $s = site_url($s);
        } elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $s) && preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)#i', $s)) {
            $s = 'https://' . $s; // "example.com/x"
        }
        return preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $s) ? $s : null;
    }

    /** Format a raw value for a property kind. Null when empty or invalid. */
    public static function format(mixed $v, string $kind, string $path = ''): mixed
    {
        if ($v === null || $v === '' || $v === []) {
            return null;
        }
        switch ($kind) {
            case 'html':
                $s = trim(is_array($v) ? implode("\n", array_map('strval', $v)) : (string)$v);
                return $s === '' ? null : $s;
            case 'list':
                $items = is_array($v) ? $v : preg_split('/\R/', (string)$v);
                if (!is_array($v) && count($items) === 1 && preg_match('#<(li|p)\b#i', (string)$v)) {
                    preg_match_all('#<(li|p)\b[^>]*>(.*?)</\1>#is', (string)$v, $m);
                    $items = $m[2];
                }
                $items = array_values(array_filter(array_map([self::class, 'plain'], (array)$items), 'strlen'));
                return $items ? (count($items) === 1 && !str_contains($path, 'recipe') ? $items[0] : $items) : null;
            case 'urls':
                $items = is_array($v) ? $v : preg_split('/[\s,]+/', (string)$v);
                $items = array_values(array_unique(array_filter(array_map([self::class, 'absUrl'], (array)$items))));
                return $items ? (count($items) === 1 ? $items[0] : $items) : null;
            case 'url':
                return self::absUrl($v);
            case 'image':
                if (is_array($v)) {
                    $items = array_values(array_filter(array_map([self::class, 'absUrl'], $v)));
                    return $items ? (count($items) === 1 ? $items[0] : $items) : null;
                }
                return self::absUrl($v);
            case 'date':
            case 'datetime':
                $s = self::plain($v);
                if (preg_match('/^\d{4}$/', $s)) {
                    return $s; // a year alone is a valid ISO 8601 date
                }
                $ts = strtotime($s);
                if ($ts === false) {
                    return null;
                }
                if ($kind === 'date') {
                    return date('Y-m-d', $ts);
                }
                // Keep the offset of the input; values without one are in the site's time zone.
                try {
                    $hasTz = (bool)preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s);
                    $d = new \DateTimeImmutable($s, $hasTz ? null : new \DateTimeZone((string)Options::get('timezone', 'UTC')));
                    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $d->format('Y-m-d') : $d->format('c');
                } catch (\Throwable) {
                    return null;
                }
            case 'duration':
                $s = strtoupper(self::plain($v));
                if (preg_match('/^P(T?\d|\d)/', $s)) {
                    return $s;
                }
                if (is_numeric($s)) {
                    return 'PT' . (int)round((float)$s) . 'M';
                }
                $h = preg_match('/(\d+)\s*(H|HR|HRS|HOUR|HOURS)\b/', $s, $m) ? (int)$m[1] : 0;
                $min = preg_match('/(\d+)\s*(M|MIN|MINS|MINUTE|MINUTES)\b/', $s, $m2) ? (int)$m2[1] : 0;
                return $h || $min ? 'PT' . ($h ? $h . 'H' : '') . ($min ? $min . 'M' : '') : null;
            case 'number':
                if (is_int($v) || is_float($v)) {
                    return $v;
                }
                $s = str_replace([',', ' '], '', self::plain($v));
                return preg_match('/-?\d+(\.\d+)?/', $s, $m) ? $m[0] + 0 : null;
            case 'bool':
                if (is_bool($v)) {
                    return $v;
                }
                $s = strtolower(self::plain($v));
                return in_array($s, ['1', 'yes', 'true', 'on', 'y'], true) ? true : (in_array($s, ['0', 'no', 'false', 'off', 'n'], true) ? false : null);
            case 'currency':
                $s = strtoupper(self::plain($v));
                $symbols = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP', '₹' => 'INR', '¥' => 'JPY', 'RS' => 'INR', 'RS.' => 'INR'];
                $s = $symbols[$s] ?? $s;
                return preg_match('/^[A-Z]{3}$/', $s) ? $s : null;
            case 'enum':
                $s = self::plain(is_array($v) ? reset($v) : $v);
                if (preg_match('#^https?://schema\.org/[A-Za-z]+$#', $s)) {
                    return str_replace('http://', 'https://', $s);
                }
                $s = str_replace(' ', '', ucwords(strtolower(preg_replace('/[^A-Za-z0-9]+/', ' ', $s))));
                return $s === '' ? null : 'https://schema.org/' . $s;
            case 'upper':
                $items = array_values(array_filter(array_map(static fn($x) => trim((string)preg_replace('/[^A-Z0-9]+/', '_', strtoupper(self::plain($x))), '_'),
                    is_array($v) ? $v : [$v]), 'strlen'));
                return $items ? (count($items) === 1 ? $items[0] : $items) : null;
            case 'subtype':
                $s = str_replace(' ', '', ucwords(self::plain(is_array($v) ? reset($v) : $v)));
                return preg_match('/^[A-Z][A-Za-z]{2,60}$/', $s) ? $s : null;
            default:
                $s = self::plain($v);
                return $s === '' ? null : $s;
        }
    }

    /** Set a dotted property path, creating typed nested objects. */
    private static function setPath(array &$node, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $ref = &$node;
        $prefix = '';
        foreach ($parts as $i => $part) {
            $prefix = $prefix === '' ? $part : "$prefix.$part";
            if ($i === count($parts) - 1) {
                $ref[$part] = $value;
                break;
            }
            if (!isset($ref[$part]) || !is_array($ref[$part]) || array_is_list($ref[$part])) {
                $ref[$part] = [];
            }
            $type = self::NESTED[$prefix] ?? self::NESTED[$part] ?? null;
            if ($type && !isset($ref[$part]['@type']) && !isset($ref[$part]['@id'])) {
                $ref[$part] = ['@type' => $type] + $ref[$part];
            }
            $ref = &$ref[$part];
        }
    }

    /** Remove empty values and objects that are left with only a type. */
    public static function clean(array $node): array
    {
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $v = self::clean($v);
                $meaningful = array_diff_key($v, ['@type' => 1]);
                if ($v === [] || (!array_is_list($v) && $meaningful === [])) {
                    unset($node[$k]);
                    continue;
                }
                $node[$k] = $v;
            } elseif ($v === null || $v === '') {
                unset($node[$k]);
            }
        }
        return array_is_list($node) ? array_values($node) : $node;
    }

    /** Drop nested objects that would be invalid for Google (half a rating, coordinates, salary). */
    private static function validate(array $node): array
    {
        foreach ($node as $k => $v) {
            if (!is_array($v) || array_is_list($v)) {
                continue;
            }
            $v = self::validate($v);
            $ok = match ($v['@type'] ?? '') {
                'AggregateRating' => isset($v['ratingValue']) && (isset($v['reviewCount']) || isset($v['ratingCount'])),
                'GeoCoordinates' => isset($v['latitude'], $v['longitude']),
                'Offer' => isset($v['price']) || isset($v['availability']) || isset($v['category']),
                'MonetaryAmount' => isset($v['currency'], $v['value']),
                'QuantitativeValue' => isset($v['value']) || isset($v['minValue']) || isset($v['maxValue']),
                default => true,
            };
            if ($ok && ($v['@type'] ?? '') === 'Offer' && isset($v['price']) && !isset($v['priceCurrency'])) {
                $ok = isset($v['availability']); // a price without a currency is ambiguous
                unset($v['price']);
            }
            if ($ok) {
                $node[$k] = $v;
            } else {
                unset($node[$k]);
            }
        }
        return $node;
    }

    /** Problems with a mapping for the admin screen: required properties that are not mapped. */
    public static function missing(string $schema, array $map): array
    {
        $out = [];
        foreach (self::props($schema) as $path => $def) {
            if ($def['req'] && ($map[$path] ?? '') === '') {
                $out[] = $def['label'];
            }
        }
        return $out;
    }

    /** ItemList node for a listing of posts (taxonomy archives). */
    public static function itemList(array $items, string $canonical, int $page = 1, int $perPage = 0): ?array
    {
        if (!$items) {
            return null;
        }
        $offset = $page > 1 && $perPage > 0 ? ($page - 1) * $perPage : 0;
        $list = [];
        foreach (array_values($items) as $i => $p) {
            $list[] = ['@type' => 'ListItem', 'position' => $offset + $i + 1, 'url' => Posts::permalink($p, true)];
        }
        return ['@type' => 'ItemList', '@id' => $canonical . '#itemlist', 'numberOfItems' => count($list), 'itemListElement' => $list];
    }

    /**
     * FAQ questions found in content: a heading (h2-h4) ending in "?" followed by the answer, up to the next heading.
     * At least two are needed. Returns schema Question nodes.
     */
    public static function faq(string $html): array
    {
        if (!str_contains($html, '?') || !preg_match_all('#<h([2-4])\b[^>]*>(.*?)</h\1>#is', $html, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $out = [];
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $q = self::plain($m[2][$i][0]);
            if ($q === '' || !str_ends_with($q, '?')) {
                continue;
            }
            $start = $m[0][$i][1] + strlen($m[0][$i][0]);
            $end = $i + 1 < $count ? $m[0][$i + 1][1] : strlen($html);
            $answer = trim(strip_tags(Seo::stripCodes(substr($html, $start, $end - $start)), '<p><br><ul><ol><li><a><strong><b><em><i>'));
            if (self::plain($answer) === '') {
                continue;
            }
            $out[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]];
        }
        return count($out) >= 2 ? $out : [];
    }

    /** A recent published item of a type (for previews and the Rich Results test). */
    public static function sample(string $postType): ?array
    {
        $row = DB::row("SELECT * FROM {{posts}} WHERE type = :t AND status = 'publish' ORDER BY published_at DESC LIMIT 1", ['t' => $postType]);
        return $row ? Posts::hydrate($row) : null;
    }

    /** JSON-LD graph a visitor would get on a post (admin preview). */
    public static function preview(array $post): array
    {
        $saved = Seo::$ctx;
        $post['terms'] = Terms::forPosts([(int)$post['id']])[(int)$post['id']] ?? [];
        Seo::$ctx = ['kind' => 'single', 'post' => $post];
        try {
            return Seo::schema();
        } finally {
            Seo::$ctx = $saved;
        }
    }

    /** Save the "Schema by type" screen. */
    public static function saveAdmin(array $in): void
    {
        $type = (string)($in['type'] ?? '');
        if ($type !== '' && ($def = ContentModel::type($type)) && !empty($def['public'])) {
            $schema = (string)($in['schema'] ?? '');
            if (array_key_exists($schema, Seo::SCHEMA_TYPES)) {
                $all = Options::get('seo_types') ?: [];
                $all[$type] = ['schema' => $schema] + ($all[$type] ?? []) + Seo::typeDefaults($type);
                Options::set('seo_types', $all);
            }
            if (self::mappable($schema)) {
                $valid = [];
                foreach (self::sources($type) as $group) {
                    $valid += $group;
                }
                $map = [];
                foreach (self::props($schema) as $path => $p) {
                    $src = (string)($in['map'][$path] ?? '');
                    if ($src === '=') {
                        $fixed = trim(strip_tags((string)($in['fixed'][$path] ?? '')));
                        $src = $fixed === '' ? '' : '=' . mb_substr($fixed, 0, 500);
                    } elseif ($src !== '' && !isset($valid[$src])) {
                        $src = '';
                    }
                    $map[$path] = $src;
                }
                $all = self::maps();
                $all[$type][$schema] = $map;
                Options::set('seo_schema_map', $all);
            }
            $images = Options::get('seo_og_type_images') ?: [];
            $img = (int)($in['og_image'] ?? 0);
            if ($img) {
                $images[$type] = $img;
            } else {
                unset($images[$type]);
            }
            Options::set('seo_og_type_images', $images);
        } else {
            $modes = [];
            foreach ((array)($in['tax'] ?? []) as $tax => $mode) {
                if (ContentModel::taxonomy((string)$tax)) {
                    $modes[$tax] = $mode === 'itemlist' ? 'itemlist' : 'collection';
                }
            }
            Options::set('seo_schema_tax', $modes);
        }
        PageCache::clear();
    }
}
