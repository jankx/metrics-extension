<?php

namespace Jankx\Features\Metrics;

use Jankx\Foundation\Application;
use Jankx\Support\Providers\ServiceProvider;
use Jankx\Features\Metrics\Services\PostViewService;
use Jankx\Features\Metrics\Render\TrendPostsBlock;
use Jankx\Gutenberg\GutenbergRepository;

class MetricServiceProvider extends ServiceProvider
{
    public function register(Application $app)
    {
        $app->singleton(PostViewService::class, function ($app) {
            return new PostViewService();
        });
    }

    public function boot(Application $app)
    {
        $postViewService = $app->make(PostViewService::class);

        // Initialize AJAX handlers
        $postViewService->initAjax();

        // Initialize frontend functionality
        $postViewService->initFrontend();

        // Track post views on single post pages (fallback for non-JS users)
        add_action('wp_head', function () use ($postViewService) {
            if (is_single() && !is_admin()) {
                $postViewService->trackPostView();
            }
        });

        // Make service available globally
        add_action('init', function () use ($postViewService) {
            $GLOBALS['jankx_post_view_service'] = $postViewService;
        });

        // Add "Post Views" option to Gutenberg query options
        add_filter('jankx/gutenberg/query-options/order-by', [$this, 'addPostViewsOrderByOption']);

        // Add "Most Views" query preset to the dynamic data layout block
        add_filter('jankx/gutenberg/query-options/query-presets', [$this, 'addPostViewsQueryPreset']);

        // Filter WP_Query to handle post_views orderby
        add_action('pre_get_posts', [$this, 'handlePostViewsOrderBy'], 10);

        // Build query attributes for the "Most Views" preset
        add_filter('jankx/dynamic-data-layout/query-builder', [$this, 'buildMostViewsPreset'], 10, 2);

        // Register Trend Posts block
        add_action('jankx/gutenberg/register-blocks', [$this, 'registerTrendPostsBlock']);
    }

    /**
     * Register Trend Posts block
     *
     * @param GutenbergRepository $repository
     * @return void
     */
    public function registerTrendPostsBlock(GutenbergRepository $repository)
    {
        $block_path = implode(DIRECTORY_SEPARATOR, [
            dirname(__FILE__),
            'blocks',
            'trend-posts'
        ]);
        $repository->registerBlock(TrendPostsBlock::class, $block_path);
    }

    /**
     * Add "Post Views" option to order by dropdown
     *
     * @param array $options Existing order by options
     * @return array Modified options
     */
    public function addPostViewsOrderByOption(array $options): array
    {
        $options[] = [
            'value' => 'post_views',
            'label' => __('Post Views (Lượt xem)', 'jankx'),
        ];

        return $options;
    }

    /**
     * Add "Most Views" query preset to the dynamic data layout block.
     *
     * @param array $presets Existing query preset options
     * @return array Modified query preset options
     */
    public function addPostViewsQueryPreset(array $presets): array
    {
        $presets[] = [
            'value' => 'most_views',
            'label' => __('Most Views (Xem nhiều nhất)', 'jankx'),
            'postType' => null,
            'help' => __('Display posts sorted by the most post views.', 'jankx'),
        ];

        return $presets;
    }

    /**
     * Build query attributes for the "Most Views" preset.
     *
     * @param array $attributes Block attributes
     * @param string $preset Query preset name
     * @return array Modified attributes
     */
    public function buildMostViewsPreset(array $attributes, string $preset): array
    {
        if ($preset !== 'most_views') {
            return $attributes;
        }

        $attributes['orderBy'] = 'post_views';
        $attributes['order'] = 'DESC';

        return $attributes;
    }

    /**
     * Handle post views orderby in WP_Query
     *
     * @param \WP_Query $query The WP_Query instance
     * @return void
     */
    public function handlePostViewsOrderBy(\WP_Query $query): void
    {
        $orderby = $query->get('orderby');

        // Match both string (`post_views`) and array (`['post_views' => 'DESC', ...]`) orderby forms
        $isPostViews = $orderby === 'post_views'
            || (is_array($orderby) && isset($orderby['post_views']));

        if (!$isPostViews) {
            return;
        }

        // Set meta query parameters
        $query->set('meta_key', 'post_views_count');
        $query->set('orderby', 'meta_value_num');

        // Default to DESC if order not specified
        if (!$query->get('order')) {
            $query->set('order', 'DESC');
        }
    }
}
