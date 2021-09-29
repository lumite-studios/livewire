<?php

namespace Livewire;

use Exception;
use ReflectionClass;
use Illuminate\Support\Str;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;
use Mockery\Generator\StringManipulation\Pass\ClassPass;

class LivewireComponentsFinder
{
    protected $namespaces;
    protected $files;
    protected $manifest;
    protected $manifestPath;

    public function __construct(Filesystem $files, $manifestPath, $namespaces)
    {
        $this->files = $files;
        $this->namespaces = $namespaces;
        $this->manifestPath = $manifestPath;
    }

    public function find($alias)
    {
        return $this->getManifest()[$alias] ?? null;
    }

    public function getManifest()
    {
        if (! is_null($this->manifest)) {
            return $this->manifest;
        }

        if (! file_exists($this->manifestPath)) {
            $this->build();
        }

        return $this->manifest = $this->files->getRequire($this->manifestPath);
    }

    public function build()
    {
        $this->manifest = $this->getClassNames()
            ->mapWithKeys(function ($class) {
                return [$class::getName() => $class];
            })->toArray();

        $this->write($this->manifest);

        return $this;
    }

    protected function write(array $manifest)
    {
        if (! is_writable(dirname($this->manifestPath))) {
            throw new Exception('The '.dirname($this->manifestPath).' directory must be present and writable.');
        }

        $this->files->put($this->manifestPath, '<?php return '.var_export($manifest, true).';', true);
    }

    public function getClassNames()
    {
		return collect($this->namespaces)->flatMap(function($prefix, $namespace) {
			return $this->getClassesInNamespace($namespace);
		})->filter(function(string $class) {
			return is_subclass_of($class, Component::class) &&
				! (new \ReflectionClass($class))->isAbstract();
		});
    }

    private function getClassesInNamespace($namespace)
    {
        $composer = require(base_path('vendor/autoload.php'));
        return collect(collect($composer->getPrefixesPsr4())->filter(function($path, $psr4) use($namespace) {
            return Str::contains($namespace, $psr4);
        })->flatMap(function($path, $psr4) use($namespace) {
            return [Str::replace('\\', '/', Str::replace($psr4, '', $namespace)) => $path];
        }))->flatMap(function($path, $folder) use($namespace) {
            return collect(File::allFiles($path))->filter(function($file) use($folder) {
                return Str::contains(pathinfo($file->getRelativePathName())['dirname'], $folder);
            })->map(function($file) use($namespace, $folder) {
                return explode('.', $namespace.Str::replace('/', '\\', Str::replace($folder, '', $file->getRelativePathName())))[0];
            });
        });
    }
}
