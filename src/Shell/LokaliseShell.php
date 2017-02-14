<?php

namespace Lokalise\Shell;

use Cake\Console\Shell;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Network\Http\Client;
use Cake\Utility\Hash;
use ZipArchive;

class LokaliseShell extends Shell {

    /**
     * The template string for the lokalise api endpoint
     *
     * @var string
     */
    const API_ENDPOINT = 'https://lokalise.co/api/%s';

    /**
     * {@inheritDocs}
     *
     */
    public function getOptionParser()
    {
        return parent::getOptionParser()
            ->addSubCommand('push', [
                'help' => 'Pushes the translation template files (.pot) to lokalise and merges with existing translations upstream',
                'parser' => [
                    'options' => [
                        'locales' => [
                            'short' => 'l',
                            'help' => 'A comma separated list of all the locales that should be updated with the template files'
                        ],
                        'tags' => [
                            'help' => 'A comma separated list of words that should be used as tags for the uploaded files'
                        ],
                        'hidden' => [
                            'help' => 'Add this flag if you wish to make the new translation strings hidden in the interface',
                            'boolean' => true,
                            'default' => false
                        ]
                    ]
                ]
            ])
            ->addSubcommand('pull', [
                'help' => 'Dowloads all translation strings from lokalise and place them in the right folders for Cake to pick up',
                'parser' => [
                    'options' => [
                        'locales' => [
                            'short' => 'l',
                            'help' => 'A comma separated list of all the locales that should be downloaded. By default all locales are downloaded'
                        ],
                    ]
                ]
            ]);
    }

    /**
     * Downloads all translations and upakcs them into the Locale folder
     *
     * @return mixed
     */
    public function pull()
    {
        $client = new Client;
        $project = Configure::read('Lokalise.project');
        $token = Configure::read('Lokalise.api_key');

        $locales = array_filter(array_map('trim', explode(',', Hash::get($this->params, 'locales', ''))));

        $response = $client->post(sprintf(static::API_ENDPOINT, 'project/export'), [
            'api_token' => $token,
            'id' => $project,
            'use_original' => 1,
            'type' => 'po',
            'langs' => $locales ? json_encode($locales) : null
        ]);

        $response = $response->json;

        if (empty($response['bundle']['file'])) {
           return $this->abort('No bundle file in the respose');
        }

        $bundle = 'https://s3-eu-west-1.amazonaws.com/lokalise-assets/' . $response['bundle']['file'];
        $this->processFile($bundle);
        $this->out('<success>Replaced translation files. You can now commit the changes</success>');
    }

    /**
     * Download file by link provided by API response
     *
     * @param string $path Path to zip file
     * @return mixed
     */
    protected function processFile($path)
    {
        $this->verbose("Starting download of <info>$path</info>");

        $date = gmdate('Y-m-d.H.i.s');
        $destination = sys_get_temp_dir() . DS;
        $filename = $destination . "translations_$date.zip";

        $client = new Client();
        $response = $client->get($path);

        if ($response->getStatusCode() !== 200) {
            return $this->abort('Could not download translations file');
        }

        file_put_contents($filename, $response->body());
        $this->out('<success>Successfully downloaded bundle file</success>');

        $zip = new ZipArchive;
        $zip->open($filename);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (preg_match('/\.pot$/', $stat['name'])) {
                $zip->renameIndex($i, preg_replace('/\.pot$/', '.po', $stat['name']));
            }
        }

        $zip->close();
        $zip->open($filename);
        $zip->extractTo(App::path('Locale')[0]);

        $this->verbose('Successfully extracted bundle file');
        unlink($filename);

        $this->verbose('Deleted bundle file');
    }

    /**
     * Pushs all pot files to lokalise
     *
     * @return void
     */
    public function push()
    {
        $paths = App::path('Locale');

        $client = new Client;
        $project = Configure::read('Lokalise.project');
        $token = Configure::read('Lokalise.api_key');

        $locales = array_filter(array_map('trim', explode(',', Hash::get($this->params, 'locales', ''))));
        $tags = array_filter(array_map('trim', explode(',', Hash::get($this->params, 'tags', ''))));

        if (empty($locales)) {
            $locales = [Configure::read('App.defaultLocale')];
        }

        $progress = $this->helper('Progress');

        $this->clear();
        $this->out('Starting the file upload, <info>this will take time</info> as Lokalise expects only one upload every 5 seconds');

        collection($paths)
            ->map(function ($path) {
                return glob($path . '*.pot');
            })
            ->unfold()
            ->unfold(function ($file) use ($locales) {
                foreach ($locales as $l) {
                    yield $file => $l;
                }
            })
            ->through(function ($all) use ($progress) {
                $total = iterator_count($all);
                $progress->init(['total' => $total]);

                return $all;
            })
            ->each(function ($locale, $file) use ($client, $project, $token, $tags, $progress) {
                // We need to observe the API throttling from lokalise
                sleep(5);

                $this->verbose(sprintf('Uploading file <info>%s</info> for locale <info>%s</info>', $file, $locale));

                // It is easier for all if tje files terminate with .po
                $dest = str_replace('.pot', '.po', sys_get_temp_dir() . DS . basename($file));
                file_put_contents($dest, file_get_contents($file));

                $response = $client->post(sprintf(static::API_ENDPOINT, 'project/import'), [
                    'api_token' => $token,
                    'id' => $project,
                    'file' => fopen($dest, 'r'),
                    'lang_iso' => $locale,
                    'replace' => 0,
                    'fill_empty' => 0,
                    'distinguish' => 1,
                    'hidden' => (int)$this->params['hidden'],
                    'tags' => json_encode($tags)
                ]);

                $response = $response->json;

                if ($response['response']['status'] === 'error') {
                    $this->abort(sprintf('%s - Could not upload file %s: %s', $locale, $file, $response['response']['message']));
                }

                $progress->increment(1);
                $progress->draw();
            });

        $this->clear();
        $this->nl(2);
        $this->out('<success>All Done.</success>');
    }
}
