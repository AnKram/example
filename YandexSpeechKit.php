<?php
namespace Emr\Services\SpeechDecoder;

use Emr\Api\Model\File\Flac;
use Emr\Api\Model\Services\TaskManager\EnumTaskQueue;
use Emr\Bitrix\Fonemica\EnumFileProcessingStatus;
use Emr\Bitrix\Fonemica\Files;
use Emr\Repository\HbAcmFiles;
use Emr\Repository\HbAcmMarking;
use Emr\Services\EnumTemplates;
use Emr\Services\TaskManager\TaskManager;
use Emr\Services\Telegram\TranscriptBot;
use Exception;

/**
 * Class YandexSpeechKit
 * @package Emr\Bitrix\Fonemica
 */
class YandexSpeechKit extends SpeechDecoder
{
    /**
     * Отправить файл на распознавание
     */
    private const URL_LONG_RUNNING_RECOGNIZE = 'https://transcribe.api.cloud.yandex.net/speech/stt/v2/longRunningRecognize';

    /**
     *
     */
    private const URL_OPERATIONS = 'https://operation.api.cloud.yandex.net/operations/';

    /**
     * Частота дискретизации
     */
    public const SAMPLE_RATE_HERTZ_8 = 8000;

    /**
     * Частота дискретизации
     */
    public const SAMPLE_RATE_HERTZ_16 = 16000;

    /**
     * Mode mono
     */
    protected const MONO = 'mono';

    /**
     * Mode stereo
     */
    protected const STEREO = 'stereo';

    /**
     * Mode
     */
    protected const MODE = [
        self::MONO => 1,
        self::STEREO => 2,
    ];

    /**
     * Получение ключа API
     * @return string
     */
    private static function _getApiKey()
    {
        return getenv('YANDEX_SPEECH_KIT_KEY');
    }

    /**
     * Получить результаты распознавания
     *
     * @param int    $iFileId
     * @param string $sQueueId
     *
     * @throws Exception
     */
    public static function operations(int $iFileId, string $sQueueId)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::URL_OPERATIONS . $sQueueId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Api-Key ' . self::_getApiKey(),
            'Content-Type: application/json'
        ]);
        $sResult = curl_exec($ch);
        curl_close($ch);


        $arResult = json_decode($sResult, true);

        if ($arResult['done']) {
            self::parseOperationResult($iFileId, $arResult);
        } else {
            TaskManager::AddTask(
                self::class,
                'operations',
                [
                    $iFileId,
                    $sQueueId
                ],
                EnumTaskQueue::yandexSpeechKit
            );
        }
    }

    /**
     * Обработка результата распознования
     *
     * @param int   $iFileId
     * @param array $arResult
     *
     * @throws Exception
     */
    public static function parseOperationResult(int $iFileId, array $arResult)
    {
        self::ClearData($iFileId);

        $arContent      = [];
        $arContentLeft  = [];
        $arContentRight = [];
        $obHbAcmFiles   = new HbAcmFiles();
        $obHbAcmMarking = new HbAcmMarking();

        foreach ($arResult['response']['chunks'] as $result) {

            $iChannelId   = $result['channelTag'];

            foreach ($result['alternatives'] as $alternative) {

                $sTranscript      = $alternative['text'];
                $iPhraseStartTime = 0;
                $iPhraseEndTime   = 0;
                $arWordList       = [];

                foreach ($alternative['words'] as $word) {

                    $sWord       = preg_replace('/[^\w\s]+/ui', '', $word['word']);
                    $sStartTime  = $word['startTime'];
                    $sEndTime    = $word['endTime'];
                    $iStartTime  = intval(
                        (float) preg_replace('/[^\d\.]/ui', '', $sStartTime) * self::SAMPLE_RATE_HERTZ_8
                    );
                    $iEndTime    = intval(
                        (float) preg_replace('/[^\d\.]/ui', '', $sEndTime) * self::SAMPLE_RATE_HERTZ_8
                    );

                    if (empty($iPhraseStartTime)) {
                        $iPhraseStartTime = $iStartTime;
                    }
                    $iPhraseEndTime = $iEndTime;

                    $arWordList[] = [
                        'UF_TALK_ID'     => $iFileId,
                        'UF_CHANNEL_ID'  => $iChannelId,
                        'UF_FRAGMENT_ID' => 0,
                        'UF_START'       => $iStartTime,
                        'UF_END'         => $iEndTime,
                        'UF_WORD'        => $sWord,
                    ];
                }

                $iFragmentId = $obHbAcmFiles->insertGetId(
                    [
                        'UF_TALK_ID'         => $iFileId,
                        'UF_CHANNEL_ID'      => $iChannelId,
                        'UF_DURATION'        => ($iPhraseEndTime - $iPhraseStartTime),
                        'UF_SPEECH'          => 1,// TODO
                        'UF_TEXT'            => $sTranscript,
                        'UF_SPEECH_DURATION' => ($iPhraseEndTime - $iPhraseStartTime),
                        'UF_SPEECH_START'    => $iPhraseEndTime,
                        'UF_VAD_DURATION'    => ($iPhraseEndTime - $iPhraseStartTime),
                        'UF_VAD_START'       => $iPhraseEndTime,
                        'UF_DATE_CREATE'     => date('Y-m-d H:i:s'),
                    ]
                );

                foreach ($arWordList as $arWord) {
                    $arWord['UF_FRAGMENT_ID'] = $iFragmentId;
                    $obHbAcmMarking->insertGetId($arWord);
                }

                $arContent[] = $sTranscript;

                if ($iChannelId == 1) {
                    $arContentLeft[] = $sTranscript;
                } elseif ($iChannelId == 2) {
                    $arContentRight[] = $sTranscript;
                }
            }
        }

        Files::FlacFromS3Update(
            (new Flac())
                ->setId($iFileId)
                ->setContent(implode(' ', $arContent))
                ->setContentLeft(implode(' ', $arContentLeft))
                ->setContentRight(implode(' ', $arContentRight))
                ->setSpeechRecognized(true)
                ->setStatusOfProcessing(EnumFileProcessingStatus::recognized)
        );

        $obBot = new TranscriptBot();
        $obBot->sendLog(
            date(EnumTemplates::userDateTimeFormat)
            . ' - Google Speech-to-Text'
            . ' - <strong>#' . $iFileId . '</strong> - файл распознан, символов: ' . mb_strlen(implode(' ', $arContent))
        );
    }

    /**
     * Отправить файл на распознавание
     *
     * @param int    $iFileId
     * @param string $sFilePath
     * @param string $mode
     *
     * @return string
     * @throws Exception
     * @link https://cloud.yandex.ru/docs/speechkit/stt/transcribation#sendfile
     */
    public function longRunningRecognize(int $iFileId, string $sFilePath, string $mode) : string
    {
        $sQueueId = null;
        $obBot    = new TranscriptBot();

        Files::FlacSetStatusOfProcessing(
            $iFileId,
            new EnumFileProcessingStatus(EnumFileProcessingStatus::queueRunned),
            getmypid()
        );

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::URL_LONG_RUNNING_RECOGNIZE);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Api-Key ' . self::_getApiKey(),
                'Content-Type: application/json'
            ]);
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode(
                    [
                        'config' => [
                            'specification' => [
                                'languageCode'      => 'ru-RU',
                                'profanityFilter'   => false,
                                'audioEncoding'     => 'OGG_OPUS', // TODO
                                'sampleRateHertz'   => self::SAMPLE_RATE_HERTZ_16,
                                'audioChannelCount' => ($mode == self::STEREO)
                                    ? self::MODE[self::STEREO]
                                    : self::MODE[self::MONO],
                            ]
                        ],
                        'audio' => [
                            'uri' => $sFilePath
                        ]
                    ]
                )
            );

            $sResult = curl_exec($ch);
            curl_close($ch);

            $arResult = json_decode($sResult, true);
            if (!$arResult['id']) {
                throw new Exception($sResult);
            }

            $sQueueId = $arResult['id'];

        } catch (Exception $obException) {

            Files::FlacSetStatusOfProcessing(
                $iFileId,
                new EnumFileProcessingStatus(EnumFileProcessingStatus::error),
                getmypid()
            );

            Files::FlacSetInfo(
                $iFileId,
                $obException->getMessage()
            );

            $obBot->sendError(
                date(EnumTemplates::userDateTimeFormat)
                . ' - Yandex SpeechKit'
                . ' - <strong>#' . $iFileId . '</strong> ' . $obException->getMessage()
            );
        }

        $obBot->sendLog(
            date(EnumTemplates::userDateTimeFormat)
            . ' - Yandex SpeechKit'
            . ' - <strong>#' . $iFileId . '</strong> - отправлен на распознавание, ' . $sQueueId
        );

        return $sQueueId;
    }
}