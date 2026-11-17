<?php
/** Starting points offered on the content model screens: key => [singular, plural, icon, supports, taxonomy hint]. */
return [
    'types' => [
        'project' => ['Project', 'Projects', 'briefcase', ['title', 'editor', 'excerpt', 'featured_image', 'seo', 'frontend_edit']],
        'team' => ['Team member', 'Team', 'users', ['title', 'editor', 'featured_image', 'page_attributes', 'seo']],
        'event' => ['Event', 'Events', 'calendar', ['title', 'editor', 'excerpt', 'featured_image', 'seo', 'frontend_edit']],
        'product' => ['Product', 'Products', 'shopping', ['title', 'editor', 'excerpt', 'featured_image', 'seo', 'frontend_edit']],
        'testimonial' => ['Testimonial', 'Testimonials', 'message', ['title', 'editor', 'featured_image']],
        'location' => ['Location', 'Locations', 'map-pin', ['title', 'editor', 'featured_image', 'seo']],
    ],
    'taxonomies' => [
        'topic' => ['Topic', 'Topics', true],
        'service' => ['Service', 'Services', true],
        'skill' => ['Skill', 'Skills', false],
        'region' => ['Region', 'Regions', true],
    ],
];
