<?php

namespace SilverStripe\SearchService\Jobs;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\ValidationException;
use SilverStripe\SearchService\Interfaces\ChildJobProvider;
use SilverStripe\SearchService\Interfaces\DocumentFetcherInterface;
use SilverStripe\SearchService\Service\ConfigurationAware;
use SilverStripe\SearchService\Service\DocumentFetchCreatorRegistry;
use SilverStripe\SearchService\Service\IndexConfiguration;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * @property DocumentFetcherInterface[] $fetchers
 * @property int $fetchIndex
 * @property int $fetchOffset
 * @property int $batchSize
 * @property string|null $onlyClass
 */
class ReindexJob extends AbstractChildJobProvider implements QueuedJob
{
    use Injectable;
    use ConfigurationAware;

    /**
     * @var array
     */
    private static $dependencies = [
        'Registry' => '%$' . DocumentFetchCreatorRegistry::class,
        'Configuration' => '%$' . IndexConfiguration::class,
    ];

    /**
     * @var DocumentFetchCreatorRegistry
     */
    private $registry;

    /**
     * @param string|null $onlyClass
     * @param int $batchSize
     */
    public function __construct(?string $onlyClass = null, ?int $batchSize = null) {
        parent::__construct();
        $this->onlyClass = $onlyClass;
        $this->batchSize = $batchSize ?: IndexConfiguration::singleton()->getBatchSize();
    }

    /**
     * Defines the title of the job.
     *
     * @return string
     */
    public function getTitle()
    {
        $title = 'Search service reindex all documents';
        if ($this->onlyClass) {
            $title .= ' of class ' . $this->onlyClass;
        }

        return $title;
    }

    /**
     * @return int
     */
    public function getJobType()
    {
        return QueuedJob::QUEUED;
    }

    public function setup()
    {
        $until = strtotime('-' . $this->getConfiguration()->getSyncInterval());
        $classes = $this->onlyClass ? [$this->onlyClass] : $this->getConfiguration()->getSearchableBaseClasses();

        /* @var DocumentFetcherInterface[] $fetchers */
        $fetchers = [];
        foreach ($classes as $class) {
            $fetcher = $this->getRegistry()->getFetcher($class, $until);
            if ($fetcher) {
                $fetchers[$class] = $fetcher;
            }
        }

        $steps = array_reduce($fetchers, function ($total, $fetcher) {
            /* @var DocumentFetcherInterface $fetcher */
            return $total + ceil($fetcher->getTotalDocuments() / $this->batchSize);
        }, 0);

        $this->totalSteps = $steps;
        $this->isComplete = $steps === 0;
        $this->fetchers = array_values($fetchers);
        $this->fetchIndex = 0;
        $this->fetchOffset = 0;
    }

    /**
     * Lets process a single node
     * @throws ValidationException
     */
    public function process()
    {
        /* @var DocumentFetcherInterface $fetcher */
        $fetcher = $this->fetchers[$this->fetchIndex] ?? null;
        if (!$fetcher) {
            $this->isComplete = true;
            return;
        }

        $documents = $fetcher->fetch($this->batchSize, $this->fetchOffset);
        $job = IndexJob::create($documents);
        $job->setProcessDependencies(false);
        $this->runChildJob($job);
        $nextOffset = $this->fetchOffset + $this->batchSize;
        if ($nextOffset >= $fetcher->getTotalDocuments()) {
            $this->fetchIndex++;
            $this->fetchOffset = 0;
        } else {
            $this->fetchOffset = $nextOffset;
        }
        $this->currentStep++;
    }

    /**
     * @param int $batchSize
     * @return ReindexJob
     */
    public function setBatchSize(int $batchSize): ReindexJob
    {
        $this->batchSize = $batchSize;
        return $this;
    }

    /**
     * @return DocumentFetchCreatorRegistry
     */
    public function getRegistry(): DocumentFetchCreatorRegistry
    {
        return $this->registry;
    }

    /**
     * @param DocumentFetchCreatorRegistry $registry
     * @return ReindexJob
     */
    public function setRegistry(DocumentFetchCreatorRegistry $registry): ReindexJob
    {
        $this->registry = $registry;
        return $this;
    }

}