<?php

namespace Khepin\YamlFixturesBundle\Loader;

use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Symfony\Component\HttpKernel\KernelInterface;
use SymfonYaml\UtilsBundle\Yaml\Yaml;

class YamlLoader
{
    protected $bundles;

    /**
     *
     * @var type
     */
    protected $kernel;

    /**
     * Doctrine entity manager
     * @var type
     */
    protected $object_manager;

    protected $acl_manager = null;

    /**
     * Array of all yml files containing fixtures that should be loaded
     * @var type
     */
    protected $fixture_files = array();

    /**
     * Maintains references to already created objects
     * @var type
     */
    protected $references = array();

    /**
     * The directory containing the fixtures files
     *
     * @var string
     */
    protected $directory;

    public function __construct(KernelInterface $kernel, $bundles, $directory)
    {
        $this->bundles = $bundles;
        $this->kernel = $kernel;
        $this->directory = $directory;
    }

    /**
     *
     * @param type $manager
     */
    public function setAclManager($manager = null)
    {
        $this->acl_manager = $manager;
    }

    /**
     * Returns a previously saved reference
     * @param  type $reference_name
     * @return type
     */
    public function getReference($reference_name)
    {
        return !is_null($reference_name) ? $this->references[$reference_name] : null;
    }

    /**
     * Sets a reference to an object
     * @param type $name
     * @param type $object
     */
    public function setReference($name, $object)
    {
        $this->references[$name] = $object;
    }

    /**
     * Gets all fixtures files
     */
    protected function loadFixtureFiles()
    {
        foreach ($this->bundles as $bundle) {
            $file = '*';
            if (strpos($bundle, '/')) {
                list($bundle, $file) = explode('/', $bundle);
            }
            $path = $this->kernel->locateResource('@' . $bundle);
            $files = glob($path . $this->directory . '/'.$file.'.yml');
            $this->fixture_files = array_unique(array_merge($this->fixture_files, $files));
        }
    }

    /**
     * Loads the fixtures file by file and saves them to the database
     */
    public function loadFixtures()
    {
        $this->loadFixtureFiles();
        $symfonYamlFixtures = [];
        $container = $this->kernel->getContainer();
        $parser = new Yaml($container);

        foreach ($this->fixture_files as $i => $file) {
            $c = $parser->parse($file);
            $model = key($c);
            if (strpos($model, 'SymfonYaml\CoreBundle\Entity\\') === 0) {
                $parts = explode('\\', $model);
                $className = array_pop($parts);
                $model = $container->getParameter('symfonyaml_core.primary_bundle.namespace') . "\Entity\\{$className}";
            }
            $c = current($c);

            if (isset($c['data']) && is_array($c['data']) && $c['data']) {
                $order = isset($c['data']['order']) ? (int) $c['data']['order'] : null;
                $fixtureData = $c['data'];
                $fixtureData['model'] = $model;

                // if nothing is specified, we use doctrine orm for persistence
                $persistence = isset($fixtureData['persistence']) ? $fixtureData['persistence'] : 'orm';

                $persister = $this->getPersister($persistence);
                $manager = $persister->getManagerForClass($fixtureData['model']);

                $fixture = $this->getFixtureClass($persistence);
                $fixture = new $fixture($fixtureData, $this, $file);

                // ordering by integers
                $key = isset($symfonYamlFixtures[$order]) ? ("{$order}.{$i}") : $order;
                if (is_null($order)) {
                    $key = time() . $i;
                }

                $symfonYamlFixtures[$key] = $fixture;
            }
        }
        ksort($symfonYamlFixtures, SORT_NUMERIC);

        foreach ($symfonYamlFixtures as $fixture) {
            $fixture->load($manager, func_get_args());
        }
    }

    /**
     * Remove all fixtures from the database
     */
    public function purgeDatabase($persistence, $databaseName = null, $withTruncate = false)
    {
        $setForeignKeyChecks = function ($flag) use ($persistence, $databaseName) {
            $this->getPersister($persistence)
            ->getManager($databaseName)
            ->getConnection()
            ->exec('SET FOREIGN_KEY_CHECKS=' . $flag);
        };

        $setForeignKeyChecks(0);

        $purgetools = array(
            'orm'       => array(
                'purger'    => 'Doctrine\Common\DataFixtures\Purger\ORMPurger',
                'executor'  => 'Doctrine\Common\DataFixtures\Executor\ORMExecutor',
            ),
            'mongodb'   => array(
                'purger'    => 'Doctrine\Common\DataFixtures\Purger\MongoDBPurger',
                'executor'  => 'Doctrine\Common\DataFixtures\Executor\MongoDBExecutor',
            )
        );
        // Retrieve the correct purger and executor
        $purge_class = $purgetools[$persistence]['purger'];
        $executor_class = $purgetools[$persistence]['executor'];

        // Instanciate purger and executor
        $persister = $this->getPersister($persistence);
        $entityManagers = ($databaseName)
            ? array($persister->getManager($databaseName))
            : $persister->getManagers();

        foreach ($entityManagers as $entityManager) {
            $purger = new $purge_class($entityManager);
            if ($withTruncate && $purger instanceof ORMPurger) {
                $purger->setPurgeMode(ORMPurger::PURGE_MODE_TRUNCATE);
            }
            $executor = new $executor_class($entityManager, $purger);
            // purge
            $executor->purge();
        }

        $setForeignKeyChecks(1);
    }

    /*
     * Returns the doctrine persister for the given persistence layer
     * @return ManagerRegistry
     */
    public function getPersister($persistence)
    {
        $managers = array(
            'orm'       => 'doctrine',
            'mongodb'   => 'doctrine_mongodb',
        );

        return $this->kernel->getContainer()->get($managers[$persistence]);
    }

    /**
     * @return string classname
     */
    public function getFixtureClass($persistence)
    {
        $classes = array(
            'orm'       => 'Khepin\YamlFixturesBundle\Fixture\OrmYamlFixture',
            'mongodb'   => 'Khepin\YamlFixturesBundle\Fixture\MongoYamlFixture'
        );

        return $classes[$persistence];
    }

    /**
     * @return the service with given id
     */
    public function getService($service_id)
    {
        return $this->kernel->getContainer()->get($service_id);
    }
}
