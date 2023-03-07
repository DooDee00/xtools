<?php
declare(strict_types = 1);

namespace App\Controller;

use App\Helper\I18nHelper;
use App\Model\Edit;
use App\Model\GlobalContribs;
use App\Repository\EditRepository;
use App\Repository\GlobalContribsRepository;
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
 * This controller serves the search form and results for the Global Contributions tool.
 * @codeCoverageIgnore
 */
class GlobalContribsController extends XtoolsController
{
    protected GlobalContribsRepository $globalContribsRepo;
    protected EditRepository $editRepo;

    public function __construct(
        RequestStack $requestStack,
        ContainerInterface $container,
        CacheItemPoolInterface $cache,
        Client $guzzle,
        I18nHelper $i18n,
        ProjectRepository $projectRepo,
        UserRepository $userRepo,
        PageRepository $pageRepo,
        GlobalContribsRepository $globalContribsRepo,
        EditRepository $editRepo
    ) {
        // GlobalContribs can be very slow, especially for wide IP ranges, so limit to max 500 results.
        $this->maxLimit = 500;
        $this->globalContribsRepo = $globalContribsRepo;
        $this->editRepo = $editRepo;
        parent::__construct($requestStack, $container, $cache, $guzzle, $i18n, $projectRepo, $userRepo, $pageRepo);
    }

    /**
     * Get the name of the tool's index route. This is also the name of the associated model.
     * @return string
     * @codeCoverageIgnore
     */
    public function getIndexRoute(): string
    {
        return 'GlobalContribs';
    }

    /**
     * The search form.
     * @Route("/globalcontribs", name="GlobalContribs")
     * @Route("/ec-latestglobal", name="EditCounterLatestGlobalIndex")
     * @Route("/ec-latestglobal-contributions", name="EditCounterLatestGlobalContribsIndex")
     * @Route("/ec-latestglobaledits", name="EditCounterLatestGlobalEditsIndex")
     * @return Response
     */
    public function indexAction(): Response
    {
        // Redirect if username is given.
        if (isset($this->params['username'])) {
            return $this->redirectToRoute('GlobalContribsResult', $this->params);
        }

        // FIXME: Nasty hack until T226072 is resolved.
        $project = $this->projectRepo->getProject($this->i18n->getLang().'.wikipedia');
        if (!$project->exists()) {
            $project = $this->projectRepo->getProject($this->getParameter('central_auth_project'));
        }

        return $this->render('globalContribs/index.html.twig', array_merge([
            'xtPage' => 'GlobalContribs',
            'xtPageTitle' => 'tool-globalcontribs',
            'xtSubtitle' => 'tool-globalcontribs-desc',
            'project' => $project,

            // Defaults that will get overridden if in $this->params.
            'namespace' => 'all',
            'start' => '',
            'end' => '',
        ], $this->params));
    }

    /**
     * Display the latest global edits tool. First two routes are legacy.
     * @Route(
     *     "/ec-latestglobal-contributions/{project}/{username}",
     *     name="EditCounterLatestGlobalContribs",
     *     requirements={
     *         "username"="(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     },
     *     defaults={
     *         "project"="",
     *         "namespace"="all"
     *     }
     * )
     * @Route(
     *     "/ec-latestglobal/{project}/{username}",
     *     name="EditCounterLatestGlobal",
     *     requirements={
     *         "username"="(ipr-.+\/\d+[^\/])|([^\/]+)",
     *     },
     *     defaults={
     *         "project"="",
     *         "namespace"="all"
     *     }
     * ),
     * @Route(
     *     "/globalcontribs/{username}/{namespace}/{start}/{end}/{offset}",
     *     name="GlobalContribsResult",
     *     requirements={
     *         "username"="(ipr-.+\/\d+[^\/])|([^\/]+)",
     *         "namespace"="|all|\d+",
     *         "start"="|\d*|\d{4}-\d{2}-\d{2}",
     *         "end"="|\d{4}-\d{2}-\d{2}",
     *         "offset"="|\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}",
     *     },
     *     defaults={
     *         "namespace"="all",
     *         "start"=false,
     *         "end"=false,
     *         "offset"=false,
     *     }
     * ),
     * @return Response
     * @codeCoverageIgnore
     */
    public function resultsAction(): Response
    {
        $globalContribs = new GlobalContribs(
            $this->globalContribsRepo,
            $this->editRepo,
            $this->pageRepo,
            $this->userRepo,
            $this->user,
            $this->namespace,
            $this->start,
            $this->end,
            $this->offset,
            $this->limit
        );
        $defaultProject = $this->projectRepo->getProject($this->getParameter('central_auth_project'));

        return $this->render('globalContribs/result.html.twig', [
            'xtTitle' => $this->user->getUsername(),
            'xtPage' => 'GlobalContribs',
            'is_sub_request' => $this->isSubRequest,
            'user' => $this->user,
            'project' => $defaultProject,
            'gc' => $globalContribs,
        ]);
    }

    /************************ API endpoints ************************/

    /**
     * Get global edits made by a user, IP or IP range.
     * @Route(
     *     "/api/user/globalcontribs/{username}/{namespace}/{start}/{end}/{offset}",
     *     name="UserApiGlobalContribs",
     *     requirements={
     *         "username"="(ipr-.+\/\d+[^\/])|([^\/]+)",
     *         "namespace"="|all|\d+",
     *         "start"="|\d*|\d{4}-\d{2}-\d{2}",
     *         "end"="|\d{4}-\d{2}-\d{2}",
     *         "offset"="|\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}",
     *     },
     *     defaults={
     *         "namespace"="all",
     *         "start"=false,
     *         "end"=false,
     *         "offset"=false,
     *         "limit"=50,
     *     },
     * )
     * @return JsonResponse
     */
    public function resultsApiAction(): JsonResponse
    {
        $this->recordApiUsage('user/globalcontribs');

        $globalContribsRepo = new GlobalContribsRepository();
        $globalContribsRepo->setContainer($this->container);
        $globalContribs = new GlobalContribs($this->user, $this->namespace, $this->start, $this->end, $this->offset);
        $globalContribs->setRepository($globalContribsRepo);
        $defaultProject = ProjectRepository::getProject(
            $this->container->getParameter('central_auth_project'),
            $this->container
        );
        $defaultProject->getRepository()->setContainer($this->container);
        $this->project = $defaultProject;

        $results = $globalContribs->globalEdits();
        $results = array_map(function (Edit $edit) {
            return $edit->getForJson(true, true);
        }, array_values($results));
        $results = $this->addFullPageTitlesAndContinue('globalcontribs', [], $results);

        return $this->getFormattedApiResponse($results);
    }
}
