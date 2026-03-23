<?php

namespace Fennectra\Installer;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Créer une nouvelle application Fennectra')
            ->addArgument('name', InputArgument::REQUIRED, 'Le nom du projet')
            ->addOption('local', null, InputOption::VALUE_OPTIONAL, 'Chemin vers le skeleton local (mode dev)', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $directory = getcwd() . '/' . $name;

        if (is_dir($directory)) {
            $io->error("Le dossier '{$name}' existe déjà.");
            return Command::FAILURE;
        }

        $io->title("🦊 Fennectra — Création de '{$name}'");

        $localPath = $input->getOption('local');

        if ($localPath !== false) {
            return $this->createFromLocal($io, $name, $directory, $localPath);
        }

        return $this->createFromPackagist($io, $name, $directory);
    }

    private function createFromPackagist(SymfonyStyle $io, string $name, string $directory): int
    {
        $io->section('Téléchargement du skeleton depuis Packagist...');

        $composerCmd = $this->findComposer();
        $process = new Process([
            ...$composerCmd, 'create-project', 'fennectra/skeleton', $directory, '--prefer-dist',
        ]);
        $process->setTimeout(300);
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $io->error('Échec du téléchargement. Vérifiez votre connexion.');
            return Command::FAILURE;
        }

        $this->postInstall($io, $name, $directory);

        return Command::SUCCESS;
    }

    private function createFromLocal(SymfonyStyle $io, string $name, string $directory, string|null $skeletonPath): int
    {
        // Résolution du chemin du skeleton
        if ($skeletonPath === null) {
            // --local sans valeur : cherche le skeleton à côté de l'installer
            $skeletonPath = dirname(__DIR__, 2) . '/skeleton';
        }

        if (!is_dir($skeletonPath)) {
            $io->error("Skeleton introuvable : {$skeletonPath}");
            return Command::FAILURE;
        }

        $io->section("Copie du skeleton depuis {$skeletonPath}...");

        // Copie récursive du skeleton
        $this->copyDirectory($skeletonPath, $directory);

        // Composer install dans le nouveau projet
        $io->section('Installation des dépendances...');
        $composerCmd = $this->findComposer();
        $process = new Process([...$composerCmd, 'install'], $directory);
        $process->setTimeout(300);
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $io->error('Échec de composer install.');
            return Command::FAILURE;
        }

        $this->postInstall($io, $name, $directory);

        return Command::SUCCESS;
    }

    private function postInstall(SymfonyStyle $io, string $name, string $directory): void
    {
        // Copier .env.example → .env
        $envExample = $directory . '/.env.example';
        $envFile = $directory . '/.env';
        if (file_exists($envExample) && !file_exists($envFile)) {
            copy($envExample, $envFile);
            $io->text('  ✓ .env créé depuis .env.example');
        }

        // Créer les dossiers de stockage
        $dirs = ['storage', 'var/cache', 'var/logs', 'var/lockout'];
        foreach ($dirs as $dir) {
            $path = $directory . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
        $io->text('  ✓ Dossiers de stockage créés');

        // Rendre forge exécutable
        $forge = $directory . '/forge';
        if (file_exists($forge)) {
            chmod($forge, 0755);
            $io->text('  ✓ forge rendu exécutable');
        }

        // Copy Dockerfiles from framework vendor to project root
        $dockerFiles = [
            'Dockerfile.frankenphp',
            'Dockerfile.fpm',
            'docker-compose.yml',
        ];
        $vendorDocker = $directory . '/vendor/fennectra/framework/docker';
        if (is_dir($vendorDocker)) {
            foreach ($dockerFiles as $file) {
                $src = $vendorDocker . '/' . $file;
                $dst = $directory . '/' . $file;
                if (file_exists($src) && !file_exists($dst)) {
                    copy($src, $dst);
                }
            }
            $io->text('  ✓ Docker files copied to project root');
        }

        // Personalize README with project name
        $readme = $directory . '/README.md';
        if (file_exists($readme)) {
            $title = ucfirst(str_replace(['-', '_'], ' ', $name));
            $content = file_get_contents($readme);
            $content = str_replace('# My API', "# {$title}", $content);
            file_put_contents($readme, $content);
            $io->text('  ✓ README.md personalized');
        }

        $io->newLine();
        $io->success("Application '{$name}' créée avec succès !");

        $io->text([
            "  <info>cd {$name}</info>",
            '  <info>./forge serve</info>',
            '',
            '  Documentation : <href=https://fennectra.dev>https://fennectra.dev</>',
        ]);
    }

    /**
     * @return string[]
     */
    private function findComposer(): array
    {
        // Chercher composer dans les emplacements courants
        $paths = [
            'composer',
            'composer.bat',
            'composer.phar',
        ];

        // Windows : emplacements courants (Laragon, Scoop, Chocolatey)
        if (PHP_OS_FAMILY === 'Windows') {
            $extraPaths = [
                'C:\\laragon\\bin\\composer\\composer.bat',
                'C:\\ProgramData\\ComposerSetup\\bin\\composer.bat',
            ];
            foreach ($extraPaths as $path) {
                if (file_exists($path)) {
                    return [$path];
                }
            }
        }

        $finder = new ExecutableFinder();
        foreach ($paths as $name) {
            $found = $finder->find($name);
            if ($found) {
                return [$found];
            }
        }

        return [PHP_BINARY, 'composer.phar'];
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0775, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            // Normaliser les séparateurs de chemin (Windows)
            $subPath = str_replace('\\', '/', $iterator->getSubPathname());

            // Ignorer vendor/, .git/, var/, composer.lock
            if (
                str_starts_with($subPath, 'vendor/')
                || str_starts_with($subPath, '.git/')
                || str_starts_with($subPath, 'var/')
                || $subPath === 'composer.lock'
            ) {
                continue;
            }

            $target = $destination . '/' . $subPath;

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                $targetDir = dirname($target);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0775, true);
                }
                copy($item->getPathname(), $target);
            }
        }
    }
}
