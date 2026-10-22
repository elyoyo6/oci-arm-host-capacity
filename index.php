<?php
declare(strict_types=1);


// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
$config = new OciConfig(
    'eu-madrid-1',
    'ocid1.user.oc1..aaaaaaaanyfjempgs2s646cg4xvndmgfoavnpmhkpf6qytssto2zmph7q6wa',
    'ocid1.tenancy.oc1..aaaaaaaamldz52gjna33cjfgs4iv5dqzixfsjpas75jxoyrmofg3rcaa6b4q',
    '4d:5a:93:24:9e:b1:95:e7:18:d5:07:28:ab:87:c6:93',
    getenv('OCI_PRIVATE_KEY_PATH') ?: "asantosrodriguez60@gmail.com-2026-10-22T15_20_17.528Z.pem",
    'jXdV:EU-MADRID-1-AD-1', // null or '' or 'jYtI:PHX-AD-1' or ['jYtI:PHX-AD-1','jYtI:PHX-AD-2']
    'ocid1.subnet.oc1.eu-madrid-1.aaaaaaaaputbkyzc4momq47ceymr6hyhuzcw3z4lovwaao2rk4d7crwvyhya',
    'ocid1.image.oc1.eu-madrid-1.aaaaaaaal74fd4sfioiwskdrymmlh5b25hiofdr4sg22jix2hr7tmh6rcaxq',
    1,
    1
);

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$api = new OciApi();
if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}
$notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
    /*
     * if you have own https://core.telegram.org/bots
     * and set TELEGRAM_BOT_API_KEY and your TELEGRAM_USER_ID in .env
     *
     * then you can get notified when script will succeed.
     * otherwise - don't mind OR develop you own NotifierInterface
     * to e.g. send SMS or email.
     */
    return new \Hitrov\Notification\Telegram();
})();

$shape = 'VM.Standard.E2.1.Micro';

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

#$result = $api->getAvailabilityDomains($config);
#$result = $api->getImages($config);
#echo json_encode($result, JSON_PRETTY_PRINT);
#exit;

$instances = $api->getInstances($config);

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    echo "$existingInstances\n";
    return;
}

if (!empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    try {
        $instanceDetails = $api->createInstance($config, $shape, 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQCxwFwOW+stftsezW0sAKQ1pc9nT/7e6ScwGQAufgIdgLAQKmCsv1yjBLBHBFCaALUMPqybsigqmi845egCWjVcidfs5P5xzhNhJDdJS+lRZcHNNsgMtKjb85Y0ebF25wh9O6lJvCafQci/rnGs9T2pVePRUQgPTkuMom+yDRkWBN39D4DvkhSK6f4zZKVmYqfF2UDNETNl+4nQP7MytsJcrJxzjlN6Qh2rBUdnFVfUMpQBPn9GQL5Is0tmAtnEbI/b+lIKRe6zl/hgXwe2ntGSchks3Rtmi/j9agAo3Fzt6OnGa8ZYiOJiDveVzZdn0EL1g48UiRPbAvPozR2BqxE9 ssh-key-2026-10-22', $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "$message\n";
//            if ($notifier->isSupported()) {
//                $notifier->notify($message);
//            }

        if (
            $e->getCode() === 500 &&
            strpos($message, 'InternalError') !== false &&
            strpos($message, 'Out of host capacity') !== false
        ) {
            // trying next availability domain
            sleep(16);
            continue;
        }

        // current config is broken
        return;
    }

    // success
    $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
    echo "$message\n";
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    return;
}
