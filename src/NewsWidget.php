<?php

declare(strict_types=1);

namespace Bolt\NewsWidget;

use Bolt\Common\Exception\ParseException;
use Bolt\Common\Json;
use Bolt\Common\Str;
use Bolt\Version;
use Bolt\Widget\BaseWidget;
use Bolt\Widget\CacheAwareInterface;
use Bolt\Widget\CacheTrait;
use Bolt\Widget\Injector\AdditionalTarget;
use Bolt\Widget\Injector\RequestZone;
use Bolt\Widget\RequestAwareInterface;
use Bolt\Widget\StopwatchAwareInterface;
use Bolt\Widget\StopwatchTrait;
use Bolt\Widget\TwigAwareInterface;
use Symfony\Component\HttpClient\HttpClient;
use Illuminate\Support\Collection;

class NewsWidget extends BaseWidget implements TwigAwareInterface, RequestAwareInterface, CacheAwareInterface, StopwatchAwareInterface
{
    use CacheTrait;
    use StopwatchTrait;

    protected ?string $name = 'News Widget';
    protected string $target = AdditionalTarget::WIDGET_BACK_DASHBOARD_ASIDE_TOP;
    protected ?int $priority = 150;
    protected ?string $template = '@news-widget/news.html.twig';
    protected ?string $zone = RequestZone::BACKEND;
    protected int $cacheDuration = 4 * 3600;

    protected string $source = 'https://news.boltcms.io/';

    protected function run(array $params = []): ?string
    {
        $news = $this->getNews();

        try {
            $currentItem = $news['information'] ?? $news['error'];

            $context = [
                'title' => $currentItem['fieldValues']['title'],
                'news' => $currentItem['fieldValues']['content'],
                'link' => $currentItem['fieldValues']['link'],
                'datechanged' => $currentItem['modifiedAt'],
                'datefetched' => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            $context = [
                'type' => 'error',
                'title' => 'Unable to fetch news!',
                'link' => '',
                'news' => '<p>Invalid JSON feed returned by <code>' . $this->source . '</code></p><small>' . $e->getMessage() . ' </small>',
            ];
        }

        return parent::run($context);
    }

    /**
     * Get the news from Bolt HQ.
     */
    private function getNews(): array
    {
        $options = $this->fetchNewsOptions();

        try {
            $client = HttpClient::create();
            $fetchedNewsData = $client->request('GET', $this->source, $options)->getContent();
        } catch (\Throwable $e) {
            $message = Str::shyphenate(preg_replace('/hash=[a-z0-9%]+/i', '', $e->getMessage()));

            return [
                'error' => [
                    'type' => 'error',
                    'fieldValues' => [
                        'title' => 'Unable to fetch news!',
                        'content' => '<p>Unable to connect to ' . $this->source . '</p><small>' . $message . ' </small>',
                        'link' => null,
                    ],
                    'modifiedAt' => '0000-01-01 00:00:00',
                ],
            ];
        }

        try {
            $fetchedNewsItems = Json::parse($fetchedNewsData);
        } catch (ParseException $e) {
            // Just move on, a user-friendly notice is returned below.
            $fetchedNewsItems = [];
        }

        $news = [];

        // Iterate over the items, pick the first news-item that
        // applies and the first alert we need to show
        foreach ($fetchedNewsItems as $item) {
            $type = $item->type ?? 'information';
            if (! isset($news[$type])
                && (empty($item->target_version) || Version::compare($item->target_version, '>'))
            ) {
                $news[$type] = $item;
            }
        }

        if ($news) {
            return $news;
        }

        return [
            'error' => [
                'type' => 'error',
                'fieldValues' => [
                    'title' => 'Unable to fetch news!',
                    'content' => '<p>Unable to parse JSON from ' . $this->source . '</p>',
                    'link' => null,
                ],
                'modifiedAt' => '0000-01-01 00:00:00',
            ],
        ];
    }

    /**
     * Get the guzzle options.
     */
    private function fetchNewsOptions(): array
    {
        $conn = $this->getExtension()->getObjectManager()->getConnection();
        $db = new \Bolt\Doctrine\Version($conn);
        $config = $this->getExtension()->getBoltConfig();

        $parameters = [
            'v' => Version::VERSION,
            'php' => PHP_VERSION,
            'db_driver' => $db->getPlatform()['driver_name'],
            'db_version' => $db->getPlatform()['server_version'],
            'host' => $this->getRequest()->getHost(),
            'name' => $config->get('general/sitename'),
            'env' => $this->getExtension()->getContainer()->getParameter('kernel.environment'),
        ];

        $curlOptions = $config->get('general/curl_options', new Collection([]))->all();

        $curlOptions['timeout'] = 6;
        $curlOptions['query'] = [
            'hash' => base64_encode(serialize($parameters)),
        ];

        return $curlOptions;
    }
}
