<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\XtoolsHttpException;
use App\Helper\I18nHelper;
use App\Model\CategoryEdits;
use App\Repository\CategoryEditsRepository;
use App\Repository\EditRepository;
use App\Repository\PageRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use GuzzleHttp\Client;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * This controller serves the Category Edits tool.
 */
class CategoryEditsController extends XtoolsController
{
    protected CategoryEdits $categoryEdits;
    protected CategoryEditsRepository $categoryEditsRepo;
    protected EditRepository $editRepo;

    /** @var string[] The categories, with or without namespace. */
    protected array $categories;

    /** @var array Data that is passed to the view. */
    private array $output;

    /**
     * Get the name of the tool's index route.
     * This is also the name of the associated model.
     * @return string
     * @codeCoverageIgnore
     */
    public function getIndexRoute(): string
    {
        return 'CategoryEdits';
    }

    /**
     * @param RequestStack $requestStack
     * @param ContainerInterface $container
     * @param CacheItemPoolInterface $cache
     * @param Client $guzzle
     * @param I18nHelper $i18n
     * @param ProjectRepository $projectRepo
     * @param UserRepository $userRepo
     * @param PageRepository $pageRepo
     * @param EditRepository $editRepo
     * @param CategoryEditsRepository $categoryEditsRepo
     */
    public function __construct(
        RequestStack $requestStack,
        ContainerInterface $container,
        CacheItemPoolInterface $cache,
        Client $guzzle,
        I18nHelper $i18n,
        ProjectRepository $projectRepo,
        UserRepository $userRepo,
        PageRepository $pageRepo,
        EditRepository $editRepo,
        CategoryEditsRepository $categoryEditsRepo
    ) {
        $this->editRepo = $editRepo;
        $this->pageRepo = $pageRepo;
        $this->categoryEditsRepo = $categoryEditsRepo;
        parent::__construct($requestStack, $container, $cache, $guzzle, $i18n, $projectRepo, $userRepo, $pageRepo);
    }

    /**
     * Will redirect back to index if the user has too high of an edit count.
     * @return string
     */
    public function tooHighEditCountRoute(): string
    {
        return $this->getIndexRoute();
    }

    /**
     * Display the search form.
     * @Route("/categoryedits", name="CategoryEdits")
     * @Route("/categoryedits/{project}", name="CategoryEditsProject")
     * @return Response
     * @codeCoverageIgnore
     */
    public function indexAction(): Response
    {
        // Redirect if at minimum project, username and categories are provided.
        if (isset($this->params['project']) && isset($this->params['username']) && isset($this->params['categories'])) {
            return $this->redirectToRoute('CategoryEditsResult', $this->params);
        }

        return $this->render('categoryEdits/index.html.twig', array_merge([
            'xtPageTitle' => 'tool-categoryedits',
            'xtSubtitle' => 'tool-categoryedits-desc',
            'xtPage' => 'CategoryEdits',

            // Defaults that will get overridden if in $params.
            'namespace' => 0,
            'start' => '',
            'end' => '',
            'username' => '',
            'categories' => '',
        ], $this->params, ['project' => $this->project]));
    }

    /**
     * Set defaults, and instantiate the CategoryEdits model. This is called at the top of every view action.
     * @codeCoverageIgnore
     */
    private function setupCategoryEdits(): void
    {
        $this->extractCategories();

        $this->categoryEdits = new CategoryEdits(
            $this->categoryEditsRepo,
            $this->editRepo,
            $this->pageRepo,
            $this->userRepo,
            $this->project,
            $this->user,
            $this->categories,
            $this->start,
            $this->end,
            $this->offset
        );

        $this->output = [
            'xtPage' => 'CategoryEdits',
            'xtTitle' => $this->user->getUsername(),
            'project' => $this->project,
            'user' => $this->user,
            'ce' => $this->categoryEdits,
            'is_sub_request' => $this->isSubRequest,
        ];
    }

    /**
     * Go through the categories and normalize values, and set them on class properties.
     * @codeCoverageIgnore
     */
    private function extractCategories(): void
    {
        // Split categories by pipe.
        $categories = explode('|', $this->request->get('categories'));

        // Loop through the given categories, stripping out the namespace.
        // If a namespace was removed, it is flagged it as normalize
        // We look for the wiki's category namespace name, and the MediaWiki default
        // 'Category:', which sometimes is used cross-wiki (because it still works).
        $normalized = false;
        $nsName = $this->project->getNamespaces()[14].':';
        $this->categories = array_map(function ($category) use ($nsName, &$normalized) {
            if (0 === strpos($category, $nsName) || 0 === strpos($category, 'Category:')) {
                $normalized = true;
            }
            return preg_replace('/^'.$nsName.'/', '', $category);
        }, $categories);

        // Redirect if normalized, since we don't want the Category: prefix in the URL.
        if ($normalized) {
            throw new XtoolsHttpException(
                '',
                $this->generateUrl($this->request->get('_route'), array_merge(
                    $this->request->attributes->get('_route_params'),
                    ['categories' => implode('|', $this->categories)]
                ))
            );
        }
    }

    /**
     * Display the results.
     * @Route(
     *     "/categoryedits/{project}/{username}/{categories}/{start}/{end}/{offset}",
     *     name="CategoryEditsResult",
     *     requirements={
     *         "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *         "categories"="(.+?)(?!\/(?:|\d{4}-\d{2}-\d{2})(?:\/(|\d{4}-\d{2}-\d{2}))?)?$",
     *         "start"="|\d{4}-\d{2}-\d{2}",
     *         "end"="|\d{4}-\d{2}-\d{2}",
     *         "offset"="|\d{4}-?\d{2}-?\d{2}T?\d{2}:?\d{2}:?\d{2}",
     *     },
     *     defaults={"start"=false, "end"=false, "offset"=false}
     * )
     * @param CategoryEditsRepository $categoryEditsRepo
     * @return Response
     * @codeCoverageIgnore
     */
    public function resultAction(CategoryEditsRepository $categoryEditsRepo): Response
    {
        $this->setupCategoryEdits($categoryEditsRepo);

        return $this->getFormattedResponse('categoryEdits/result', $this->output);
    }

    /**
     * Get edits by a user to pages in given categories.
     * @Route(
     *   "/categoryedits-contributions/{project}/{username}/{categories}/{start}/{end}/{offset}",
     *   name="CategoryContributionsResult",
     *   requirements={
     *       "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *       "categories"="(.+?)(?!\/(?:|\d{4}-\d{2}-\d{2}))?",
     *       "start"="|\d{4}-\d{2}-\d{2}",
     *       "end"="|\d{4}-\d{2}-\d{2}",
     *       "offset"="|\d{4}-?\d{2}-?\d{2}T?\d{2}:?\d{2}:?\d{2}",
     *   },
     *   defaults={"start"=false, "end"=false, "offset"=false}
     * )
     * @param CategoryEditsRepository $categoryEditsRepo
     * @return Response
     * @codeCoverageIgnore
     */
    public function categoryContributionsAction(CategoryEditsRepository $categoryEditsRepo): Response
    {
        $this->setupCategoryEdits($categoryEditsRepo);

        return $this->render('categoryEdits/contributions.html.twig', $this->output);
    }

    /************************ API endpoints ************************/

    /**
     * Count the number of category edits the given user has made.
     * @Route(
     *   "/api/user/category_editcount/{project}/{username}/{categories}/{start}/{end}",
     *   name="UserApiCategoryEditCount",
     *   requirements={
     *       "username" = "(ipr-.+\/\d+[^\/])|([^\/]+)",
     *       "categories" = "(.+?)(?!\/(?:|\d{4}-\d{2}-\d{2})(?:\/(|\d{4}-\d{2}-\d{2}))?)?$",
     *       "start" = "|\d{4}-\d{2}-\d{2}",
     *       "end" = "|\d{4}-\d{2}-\d{2}"
     *   },
     *   defaults={"start" = false, "end" = false}
     * )
     * @param CategoryEditsRepository $categoryEditsRepo
     * @return JsonResponse
     * @codeCoverageIgnore
     */
    public function categoryEditCountApiAction(CategoryEditsRepository $categoryEditsRepo): JsonResponse
    {
        $this->recordApiUsage('user/category_editcount');

        $this->setupCategoryEdits($categoryEditsRepo);

        $ret = [
            'total_editcount' => $this->categoryEdits->getEditCount(),
            'category_editcount' => $this->categoryEdits->getCategoryEditCount(),
        ];

        return $this->getFormattedApiResponse($ret);
    }
}
