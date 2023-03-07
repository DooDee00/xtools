<?php

declare(strict_types = 1);

namespace App\Repository;

use App\Model\Edit;
use App\Model\Page;
use Doctrine\DBAL\Driver\ResultStatement;
use GuzzleHttp\Client;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * ArticleInfoRepository is responsible for retrieving data about a single
 * article on a given wiki.
 * @codeCoverageIgnore
 */
class ArticleInfoRepository extends Repository
{
    protected EditRepository $editRepo;
    protected UserRepository $userRepo;

    /** @var int Maximum number of revisions to process, as configured via app.max_page_revisions */
    public int $maxPageRevisions;

    /**
     * @param ContainerInterface $container
     * @param CacheItemPoolInterface $cache
     * @param Client $guzzle
     * @param LoggerInterface $logger
     * @param bool $isWMF
     * @param int $queryTimeout
     * @param EditRepository $editRepo
     * @param ParameterBagInterface $parameterBag
     */
    public function __construct(
        ContainerInterface $container,
        CacheItemPoolInterface $cache,
        Client $guzzle,
        LoggerInterface $logger,
        bool $isWMF,
        int $queryTimeout,
        EditRepository $editRepo,
        UserRepository $userRepo,
        ParameterBagInterface $parameterBag
    ) {
        $this->maxPageRevisions = (int)$parameterBag->get('app.max_page_revisions');
        $this->editRepo = $editRepo;
        $this->userRepo = $userRepo;
        parent::__construct($container, $cache, $guzzle, $logger, $isWMF, $queryTimeout);
    }

    /**
     * Factory to instantiate a new Edit for the given revision.
     * @param Page $page
     * @param array $revision
     * @return Edit
     */
    public function getEdit(Page $page, array $revision): Edit
    {
        return new Edit($this->editRepo, $this->userRepo, $page, $revision);
    }

    /**
     * Get the number of edits made to the page by bots or former bots.
     * @param Page $page
     * @param false|int $start
     * @param false|int $end
     * @param ?int $limit
     * @param bool $count Return a count rather than the full set of rows.
     * @return ResultStatement resolving with keys 'count', 'username' and 'current'.
     */
    public function getBotData(Page $page, $start, $end, ?int $limit, bool $count = false): ResultStatement
    {
        $project = $page->getProject();
        $revTable = $project->getTableName('revision');
        $userGroupsTable = $project->getTableName('user_groups');
        $userFormerGroupsTable = $project->getTableName('user_former_groups');
        $actorTable = $project->getTableName('actor', 'revision');

        $datesConditions = $this->getDateConditions($start, $end);

        if ($count) {
            $actorSelect = '';
            $groupBy = '';
        } else {
            $actorSelect = 'actor_name AS username, ';
            $groupBy = 'GROUP BY actor_user';
        }

        $limitClause = '';
        if (null !== $limit) {
            $limitClause = "LIMIT $limit";
        }

        $sql = "SELECT COUNT(DISTINCT rev_id) AS count, $actorSelect '0' AS current
                FROM (
                    SELECT rev_id, rev_actor, rev_timestamp
                    FROM $revTable
                    WHERE rev_page = :pageId
                    ORDER BY rev_timestamp DESC
                    $limitClause
                ) a
                JOIN $actorTable ON actor_id = rev_actor
                LEFT JOIN $userFormerGroupsTable ON actor_user = ufg_user
                WHERE ufg_group = 'bot' $datesConditions
                $groupBy
                UNION
                SELECT COUNT(DISTINCT rev_id) AS count, $actorSelect '1' AS current
                FROM (
                    SELECT rev_id, rev_actor, rev_timestamp
                    FROM $revTable
                    WHERE rev_page = :pageId
                    ORDER BY rev_timestamp DESC
                    $limitClause
                ) a
                JOIN $actorTable ON actor_id = rev_actor
                LEFT JOIN $userGroupsTable ON actor_user = ug_user
                WHERE ug_group = 'bot' $datesConditions
                $groupBy";

        return $this->executeProjectsQuery($project, $sql, ['pageId' => $page->getId()]);
    }

    /**
     * Get prior deletions, page moves, and protections to the page.
     * @param Page $page
     * @param false|int $start
     * @param false|int $end
     * @return string[] each entry with keys 'log_action', 'log_type' and 'timestamp'.
     */
    public function getLogEvents(Page $page, $start, $end): array
    {
        $cacheKey = $this->getCacheKey(func_get_args(), 'page_logevents');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }
        $loggingTable = $page->getProject()->getTableName('logging', 'logindex');

        $datesConditions = $this->getDateConditions($start, $end, false, '', 'log_timestamp');

        $sql = "SELECT log_action, log_type, log_timestamp AS 'timestamp'
                FROM $loggingTable
                WHERE log_namespace = '" . $page->getNamespace() . "'
                AND log_title = :title AND log_timestamp > 1 $datesConditions
                AND log_type IN ('delete', 'move', 'protect', 'stable')";
        $title = str_replace(' ', '_', $page->getTitle());

        $result = $this->executeProjectsQuery($page->getProject(), $sql, ['title' => $title])
            ->fetchAllAssociative();
        return $this->setCache($cacheKey, $result);
    }

    /**
     * Get the number of categories, templates, and files that are on the page.
     * @param Page $page
     * @return array With keys 'categories', 'templates' and 'files'.
     */
    public function getTransclusionData(Page $page): array
    {
        $cacheKey = $this->getCacheKey(func_get_args(), 'page_transclusions');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $categorylinksTable = $page->getProject()->getTableName('categorylinks');
        $templatelinksTable = $page->getProject()->getTableName('templatelinks');
        $imagelinksTable = $page->getProject()->getTableName('imagelinks');
        $sql = "(
                    SELECT 'categories' AS `key`, COUNT(*) AS val
                    FROM $categorylinksTable
                    WHERE cl_from = :pageId
                ) UNION (
                    SELECT 'templates' AS `key`, COUNT(*) AS val
                    FROM $templatelinksTable
                    WHERE tl_from = :pageId
                ) UNION (
                    SELECT 'files' AS `key`, COUNT(*) AS val
                    FROM $imagelinksTable
                    WHERE il_from = :pageId
                )";
        $resultQuery = $this->executeProjectsQuery($page->getProject(), $sql, ['pageId' => $page->getId()]);
        $transclusionCounts = [];

        while ($result = $resultQuery->fetchAssociative()) {
            $transclusionCounts[$result['key']] = (int)$result['val'];
        }

        return $this->setCache($cacheKey, $transclusionCounts);
    }

    /**
     * Get the top editors to the page by edit count.
     * @param Page $page
     * @param false|int $start
     * @param false|int $end
     * @param int $limit
     * @param bool $noBots
     * @return array
     */
    public function getTopEditorsByEditCount(
        Page $page,
        $start = false,
        $end = false,
        int $limit = 20,
        bool $noBots = false
    ): array {
        $cacheKey = $this->getCacheKey(func_get_args(), 'page_topeditors');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $project = $page->getProject();
        // Faster to use revision instead of revision_userindex in this case.
        $revTable = $project->getTableName('revision', '');
        $actorTable = $project->getTableName('actor');

        $dateConditions = $this->getDateConditions($start, $end);

        $sql = "SELECT actor_name AS username,
                    COUNT(rev_id) AS count,
                    SUM(rev_minor_edit) AS minor,
                    MIN(rev_timestamp) AS first_timestamp,
                    MIN(rev_id) AS first_revid,
                    MAX(rev_timestamp) AS latest_timestamp,
                    MAX(rev_id) AS latest_revid
                FROM $revTable
                JOIN $actorTable ON rev_actor = actor_id
                WHERE rev_page = :pageId $dateConditions";

        if ($noBots) {
            $userGroupsTable = $project->getTableName('user_groups');
            $sql .= "AND NOT EXISTS (
                         SELECT 1
                         FROM $userGroupsTable
                         WHERE ug_user = actor_user
                         AND ug_group = 'bot'
                     )";
        }

        $sql .= "GROUP BY actor_id
                 ORDER BY count DESC
                 LIMIT $limit";

        $result = $this->executeProjectsQuery($project, $sql, [
            'pageId' => $page->getId(),
        ])->fetchAllAssociative();

        return $this->setCache($cacheKey, $result);
    }

    /**
     * Get various basic info used in the API, including the number of revisions, unique authors, initial author
     * and edit count of the initial author. This is combined into one query for better performance. Caching is only
     * applied if it took considerable time to process, because using the gadget, this will get hit for a different page
     * constantly, where the likelihood of cache benefiting us is slim.
     * @param Page $page The page.
     * @return string[]|false false if the page was not found.
     */
    public function getBasicEditingInfo(Page $page)
    {
        $cacheKey = $this->getCacheKey(func_get_args(), 'page_basicinfo');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $project = $page->getProject();
        $revTable = $project->getTableName('revision');
        $userTable = $project->getTableName('user');
        $pageTable = $project->getTableName('page');
        $actorTable = $project->getTableName('actor');

        $sql = "SELECT *, (
                    SELECT user_editcount
                    FROM $userTable
                    WHERE user_id = author_user_id
                ) AS author_editcount
                FROM (
                    (
                        SELECT COUNT(rev_id) AS num_edits,
                            COUNT(DISTINCT(rev_actor)) AS num_editors,
                            SUM(rev_minor_edit) AS minor_edits
                        FROM $revTable
                        WHERE rev_page = :pageid
                        AND rev_timestamp > 0 # Use rev_timestamp index
                    ) a,
                    (
                        # With really old pages, the rev_timestamp may need to be sorted ASC,
                        #   and the lowest rev_id may not be the first revision.
                        SELECT actor_name AS author,
                               actor_user AS author_user_id,
                               rev_timestamp AS created_at,
                               rev_id AS created_rev_id
                        FROM $revTable
                        JOIN $actorTable ON actor_id = rev_actor
                        WHERE rev_page = :pageid
                        ORDER BY rev_timestamp ASC
                        LIMIT 1
                    ) b,
                    (
                        SELECT rev_timestamp AS modified_at,
                               rev_id AS modified_rev_id
                        FROM $revTable
                        JOIN $pageTable ON page_id = rev_page
                        WHERE rev_page = :pageid
                        AND rev_id = page_latest
                    ) c
                )";
        $params = ['pageid' => $page->getId()];

        // Get current time so we can compare timestamps
        // and decide whether or to cache the result.
        $time1 = time();

        /**
         * This query can sometimes take too long to run for pages with tens of thousands
         * of revisions. This query is used by the ArticleInfo gadget, which shows basic
         * data in real-time, so if it takes too long than the user probably didn't even
         * wait to see the result. We'll pass 60 as the last parameter to executeProjectsQuery,
         * which will set the max_statement_time to 60 seconds.
         */
        $result = $this->executeProjectsQuery($project, $sql, $params, 60)->fetchAssociative();

        $time2 = time();

        // If it took over 5 seconds, cache the result for 20 minutes.
        if ($time2 - $time1 > 5) {
            $this->setCache($cacheKey, $result, 'PT20M');
        }

        return $result ?? false;
    }
}
