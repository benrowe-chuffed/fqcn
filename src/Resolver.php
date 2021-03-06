<?php

declare(strict_types=1);

namespace Benrowe\Fqcn;

use Benrowe\Fqcn\Value\Psr4Namespace;
use Composer\Autoload\ClassLoader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

/**
 * Psr4 Resolver
 *
 * Help resolve a PHP PSR-4 namespace to a directory + resolve language
 * constructs (classes, interfaces and traits) that implement the provided
 * namespace
 *
 * Example:
 *
 * ```php
 * $composer = require './vendor/autoload.php';
 * $resolver = new Benrowe\Fqcn\Resolver('Namespace\\To\\Search\\For', $composer);
 * $resolver->findDirectories() // => list of directories
 * $resolver->findConstructs() => lists of all language constructs found under the namespace
 * ```
 *
 * @package Benrowe\Fqcn
 */
class Resolver
{
    /**
     * Instance of composer, since this will be used to load the ps4 prefixes
     *
     * @var ClassLoader
     */
    private $composer;

    /**
     * @var Psr4Namespace
     */
    private $namespace;

    /**
     * Resolver constructor.
     *
     * @param Psr4Namespace|string $namespace
     * @param ClassLoader $composer
     */
    public function __construct($namespace, ClassLoader $composer)
    {
        $this->setNamespace($namespace);
        $this->composer = $composer;
    }

    /**
     * Set the namespace to resolve
     *
     * @param Psr4Namespace|string $namespace $namespace
     */
    public function setNamespace($namespace)
    {
        if (!($namespace instanceof Psr4Namespace)) {
            $namespace = new Psr4Namespace($namespace);
        }
        $this->namespace = $namespace;
    }

    /**
     * Get the current namespace
     *
     * @return Psr4Namespace
     */
    public function getNamespace(): Psr4Namespace
    {
        return $this->namespace;
    }

    /**
     * Find all of the available constructs under a specific namespace
     *
     * @param  string $instanceOf optional, restrict the classes found to those
     *                            that extend from this base
     * @return array a list of FQCN's that match
     */
    public function findConstructs(string $instanceOf = null): array
    {
        $availablePaths = $this->findDirectories();

        $constructs = $this->findNamespacedConstuctsInDirectories($availablePaths, $this->namespace);

        // apply filtering
        if ($instanceOf !== null) {
            $constructs = array_values(array_filter($constructs, function ($constructName) use ($instanceOf) {
                return is_subclass_of($constructName, $instanceOf);
            }));
        }

        return $constructs;
    }

    /**
     * Resolve a psr4 based namespace to a list of absolute directory paths
     *
     * @return array list of directories this namespace is mapped to
     * @throws Exception
     */
    public function findDirectories(): array
    {
        $prefixes = $this->composer->getPrefixesPsr4();
        // pluck the best namespace from the available
        $namespacePrefix   = $this->findNamespacePrefix($this->namespace, array_keys($prefixes));
        if (!$namespacePrefix) {
            throw new Exception('Could not find registered psr4 prefix that matches '.$this->namespace);
        }

        return $this->buildDirectoryList($prefixes[$namespacePrefix->getValue()], $this->namespace, $namespacePrefix);
    }

    /**
     * Build a list of absolute paths, for the given namespace, based on the relative $prefix
     *
     * @param  array  $directories the list of directories (their position relates to $prefix)
     * @param  Psr4Namespace $namespace   The base namespace
     * @param  Psr4Namespace $prefix      The psr4 namespace related to the list of provided directories
     * @return array directory paths for provided namespace
     */
    private function buildDirectoryList(array $directories, Psr4Namespace $namespace, Psr4Namespace $prefix): array
    {
        $discovered = [];
        foreach ($directories as $path) {
            $path = (new PathBuilder($path, $prefix))->resolve($namespace);
            // convert the rest of the relative path, from the prefix into a directory slug
            if ($path && is_dir($path)) {
                $discovered[] =  $path;
            }
        }
        return $discovered;
    }

    /**
     * Find the best psr4 namespace prefix, based on the supplied namespace, and
     * list of provided prefix
     *
     * @param Psr4Namespace $namespace
     * @param array  $namespacePrefixes
     * @return Psr4Namespace
     */
    private function findNamespacePrefix(Psr4Namespace $namespace, array $namespacePrefixes)
    {
        $prefixResult = null;

        // find the best matching prefix!
        foreach ($namespacePrefixes as $prefix) {
            $prefix = new Psr4Namespace($prefix);
            if ($namespace->startsWith($prefix) &&
                ($prefixResult === null || $prefix->length() > $prefixResult->length())
            ) {
                // if we have a match, and it's longer than the previous match
                $prefixResult = $prefix;
            }
        }
        return $prefixResult;
    }

    /**
     * Retrieve a directory iterator for the supplied path
     *
     * @param  string $path The directory to iterate
     * @return RegexIterator
     */
    private function getDirectoryIterator(string $path): RegexIterator
    {
        $dirIterator = new RecursiveDirectoryIterator($path);
        $iterator = new RecursiveIteratorIterator($dirIterator);
        return new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);
    }

    /**
     * Determine if the construct (class, interface or trait) exists
     *
     * @param string $constructName
     * @return bool
     */
    private function languageConstructExists(string $constructName): bool
    {
        return
            $this->checkConstructExists($constructName, false) ||
            $this->checkConstructExists($constructName);
    }

    /**
     * Determine if the construct exists
     *
     * @param  string $constructName
     * @param  bool $autoload trigger the autoloader to be fired, if the construct
     *                        doesn't exist
     * @return bool
     */
    private function checkConstructExists(string $constructName, bool $autoload = true): bool
    {
        return
            class_exists($constructName, $autoload) ||
            interface_exists($constructName, $autoload) ||
            trait_exists($constructName, $autoload);
    }

    /**
     * Process a list of directories, searching for language constructs (classes,
     * interfaces, traits) that exist in them, based on the supplied base
     * namespace
     *
     * @param  array  $directories list of absolute directory paths
     * @param  Psr4Namespace $namespace   The namespace these directories are representing
     * @return array
     */
    private function findNamespacedConstuctsInDirectories(array $directories, Psr4Namespace $namespace): array
    {
        $constructs = [];
        foreach ($directories as $path) {
            $constructs = array_merge($constructs, $this->findNamespacedConstuctsInDirectory($path, $namespace));
        }

        sort($constructs);

        return $constructs;
    }

    /**
     * Recurisvely scan the supplied directory for language constructs that are
     * $namespaced
     *
     * @param  string $directory The directory to scan
     * @param  Psr4Namespace $namespace the namespace that represents this directory
     * @return array
     */
    private function findNamespacedConstuctsInDirectory(string $directory, Psr4Namespace $namespace): array
    {
        $constructs = [];

        foreach ($this->getDirectoryIterator($directory) as $file) {
            $fqcn = $namespace.strtr(substr($file[0], strlen($directory) + 1, -4), '//', '\\');
            if ($this->languageConstructExists($fqcn)) {
                $constructs[] = $fqcn;
            }
        }

        return $constructs;
    }
}
