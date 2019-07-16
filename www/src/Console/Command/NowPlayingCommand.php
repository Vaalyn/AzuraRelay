<?php
namespace App\Console\Command;

use Azura\Console\Command\CommandAbstract;
use Azura\Settings;
use AzuraCast\Api\Client;
use AzuraCast\Api\Dto\AdminRelayDto;
use AzuraCast\Api\Dto\AdminRelayUpdateDto;
use GuzzleHttp\Psr7\Uri;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class NowPlayingCommand extends CommandAbstract
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('app:nowplaying')
            ->setDescription('Send "Now Playing" information to the parent AzuraCast server.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('AzuraRelay Now Playing');

        $baseUrl = getenv('AZURACAST_BASE_URL');
        $apiKey = getenv('AZURACAST_API_KEY');

        if (empty($baseUrl) || empty($apiKey)) {
            $io->error('Base URL or API key is not specified. Please supply these values in "azurarelay.env" to continue!.');
            return 1;
        }

        /** @var Client $api */
        $api = $this->get(Client::class);

        /** @var Settings $settings */
        $settings = $this->get(Settings::class);

        $configDir = dirname($settings[Settings::BASE_DIR]).'/stations';
        $relayInfoPath = $configDir.'/stations.json';

        if (!file_exists($relayInfoPath)) {
            $io->error('Relay information file doesn\'t exist! Skipping.');
            return 1;
        }

        $relaysRaw = json_decode(file_get_contents($relayInfoPath), true);

        $np = [];
        foreach($relaysRaw as $relayRaw) {
            $relay = AdminRelayDto::fromArray($relayRaw);

            $localUri = (new Uri('http://localhost'))
                ->withPort($relay->getPort());

            $npAdapter = new \NowPlaying\Adapter\Icecast($localUri);
            $npAdapter->setAdminPassword($relay->getAdminPassword());

            foreach($relay->getMounts() as $mount) {
                try {
                    $np[$relay->getId()][$mount->getPath()] = $npAdapter->getNowPlaying($mount->getPath());
                } catch(\NowPlaying\Exception $e) {
                    $io->error(sprintf('NowPlaying adapter error: %s', $e->getMessage()));
                }
            }
        }

        if (!empty($np)) {
            $api->admin()->relays()->update(new AdminRelayUpdateDto(
                getenv('AZURARELAY_BASE_URL'),
                getenv('AZURARELAY_NAME'),
                (bool)getenv('AZURARELAY_IS_PUBLIC'),
                $np
            ));
        }

        $io->success('Now Playing updated!');
        return 0;
    }
}