<?php

require('../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\Client;

$app = new Silex\Application();

$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => 'php://stderr',
));

$app->before(function (Request $request) use($bot) {
    // TODO validation
});

$app->get('/callback', function (Request $request) use ($app) {
    $response = "";
    if ($request->query->get('hub_verify_token') === getenv('FACEBOOK_PAGE_VERIFY_TOKEN')) {
        $response = $request->query->get('hub_challenge');
    }

    return $response;
});

$app->post('/callback', function (Request $request) use ($app) {
    // Let's hack from here!
    $body = json_decode($request->getContent(), true);
    $client = new Client(['base_uri' => 'https://graph.facebook.com/v2.6/']);

    foreach ($body['entry'] as $obj) {
        $app['monolog']->addInfo(sprintf('obj: %s', json_encode($obj)));

        foreach ($obj['messaging'] as $m) {
            $app['monolog']->addInfo(sprintf('messaging: %s', json_encode($m)));
            $from = $m['sender']['id'];
            $text = $m['message']['text'];

            if ($text) {
                //google booksでISBNと正確なタイトル取得
                $encode_title = urlencode($text);
                $book_info_json = file_get_contents("https://www.googleapis.com/books/v1/volumes?q=intitle:".$encode_title);
                $book_info = json_decode($book_info_json, true);

                //google booksで本が見つかった場合の処理
                if(isset($book_info['items'])) {
                    //本が何冊ヒットしても一番目?に取得したデータのISBN10(10がなければ勝手に13になると思う)を取得
                    $isbn = $book_info['items'][0]['volumeInfo']['industryIdentifiers'][0]['identifier'];
                    $title = $book_info['items'][0]['volumeInfo']['title'];

                    $api_key = 'appkey={c55656f2c3b1269ba1e9e98114432e4e}';
                    $system_id = 'Chiba_Funabashi';

                    //calil APIで本があるか確認
                    do {
                        $res_json = file_get_contents("http://api.calil.jp/check?".$api_key."&isbn=".$isbn."&systemid=".$system_id."&callback=no");
                        $obj = json_decode($res_json);
                        //continueが1の場合は処理が終わっていないため処理を続ける
                        $i = $obj->continue;
                        //APIの説明に2秒以上の間隔をあけろとあるため2秒まつ
                        sleep(2);
                    } while($i == 1);

                    //予約用URLがない場合は蔵書なしと判断
                    if(!empty($obj->books->$isbn->$system_id->reserveurl)) {
                        $url = $obj->books->$isbn->$system_id->reserveurl;
                        $lend_info = $obj->books->$isbn->$system_id->libkey;
                        foreach ($lend_info as $lib => $status) {
                            $response = 'タイトル:'.$title.'URL:'.$url.'所蔵館';
                            $response .= '所蔵館:'.$lib.nl2br('\n').'貸出状況:'.$status.nl2br('\n');
                        }
                    // 図書館に本がなかった場合の処理
                    }else {
                        $response = '船橋の図書館に該当の本がありませんでした。';
                    }
                //本が見つからなかった場合の処理
                }else {
                    $response = '検索された本が世の中から見つかりませんでした。';
                }

                $path = sprintf('me/messages?access_token=%s', getenv('FACEBOOK_PAGE_ACCESS_TOKEN'));
                $json = [
                    'recipient' => [
                        'id' => $from,
                    ],
                    'message' => [
                        'text' => sprintf($response),
                    ],
                ];
                $client->request('POST', $path, ['json' => $json]);
            }
        }

    }

    return 0;
});

$app->run();
