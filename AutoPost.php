<?php
////////////////////////////////////////////////////////////////////////////
// Macでこのphpを実行することを推奨。
// また、事前にクライアントの証明書とクライアントの秘密鍵とそのパスフレーズ、
// プライベートCAの公開鍵証明書を作成しておくこと。
//
// 1. アーカイブを展開。
// 2. 「修正すべき変数」セクションの変数を適宜修正。
// 3. WAFに引っかかるのを防ぐため、.htaccessに以下の記述を入れる
// <IfModule mod_siteguard.c>
//   SiteGuard_User_ExcludeSig ip(このスクリプトを実行するパソコンのIPアドレス)
// </IfModule>
// 4. このphpファイルを実行。
//
////////////////////////////////////////////////////////////////////////////

require_once "IXR_Library.php";

///////////////////////修正すべき変数ここから///////////////////////////////
$domain = 'wordpressblog.net'; // ドメイン名(commonNameと同じものを指定。FQDNになる場合も?)
$xmlrpc_path = '/xmlrpc.php'; // サーバー上のxmlrpc.phpのパス
$pubcert_path = '/Users/hogehoge/demoCA/newcert.pem'; // クライアントの証明書
$privatekey_path = '/Users/hogehoge/demoCA/newkey.pem'; // クライアントの秘密鍵
$sec_pass = 'passphrase'; // newkey.pemのパスフレーズ
$cacert_path = '/Users/hogehoge/demoCA/cacert.pem'; // CAの公開鍵証明書
$json_files_path = '/Users/user/Takeout/Google+ ストリーム/投稿'; // jsonファイルのあるフォルダのパス
$user = 'user'; // ユーザー名
$pass = 'password'; // パスワード

////////////////////////修正すべき変数ここまで//////////////////////////////


// 履歴記録用のファイル
$hist_file = __DIR__ . '/posted.txt';

function format_time($raw_time_str){
  // $raw_time_strの例
  // 2016-03-15 13:10:17+0000

  //時差
  // $time_difference = 9;

  //繰り上がりがあるかどうか
  $carry = false;

  // 年月日と時間を分割
  $split = explode(' ', $raw_time_str);

  // 時刻部分の整形
  $time_split = explode('+', $split[1]);
  $time_split = explode(':', $time_split[0]);
  $hour = $time_split[0];
  // $hour = $time_split[0] - $time_difference;
  // if($hour <= 0){
  //   $hour += 24;
  //   $carry = true;
  // }
  $time_str = $hour . ':' . $time_split[1] . ':' . $time_split[2];

  //年月日部分の整形
  $date_str = str_replace('-', '/', $split[0]);
  if($carry == true){
    // 日付の繰り上げ
    $date_str = date("Y-m-d", strtotime("$date_str -1 day"));
  }else{
    $date_str = str_replace('/', '-', $split[0]);
  }

  // $time_strの例
  // 2014-2-19 11:11:38
  return $date_str . ' ' . $time_str;
}

function get_data($post_data_dir){
  global $hist_file;
  $post_data_list = array();
  $export_json_list = array();

  // ポストしたデータの履歴データを開く
  $post_hist_id = file($hist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

  if( is_dir( $post_data_dir ) && $handle = opendir( $post_data_dir ) ) {
    while( ($file = readdir($handle)) !== false ) {
      // $fileがファイルであり、.jsonであるかどうか確認
      if( filetype( $post_path = $post_data_dir . "/" . $file ) == "file" && preg_match('#.json$#', $file)) {
        // ファイルを読み込んで連想配列を取得
        $json_data_raw = file_get_contents($post_path);
        $json_data_converted = mb_convert_encoding($json_data_raw, 'UTF-8');
        $json_data = json_decode($json_data_converted, true);

        // idを確認して、すでに投稿済みならスキップ(url末尾をidとして利用)
        preg_match('#.*/{0}/(.+)$#', $json_data['url'], $post_id_match);
        $post_id = $post_id_match[1];
        if(in_array($post_id, $post_hist_id) == False){
          // タイトルは基本的にjsonから取得
          $title = array_key_exists('content', $json_data)
                    ? substr($json_data['content'], 0, 100)
                    : $file;

          // 時刻文字列の整形
          $published_date_gmt = format_time($json_data['creationTime']);
          $updated_date = format_time($json_data['updateTime']);

          // linkがないときもあることを考慮して取得
          $link = array_key_exists('link', $json_data)
                          ? '[bcd url="' . $json_data['link']['url'] . '"]'
                          : '';

          $content = $json_data['content'];
          //contentにあるaタグを抽出し、bcdタグで囲い直す
          $content = preg_replace('/<a href=((?!>).)*>/', '[bcd url="', $content);
          $content = str_replace('</a>', '"]', $content);

          // コメントは全部'<br />'でつないで取得
          $comments = '';
          for($i = 0; $i < count($json_data['comments']); $i++){
            $comments = $comments . '<br />' . $json_data['comments'][$i]['content'];
          }

          // contentにリンクとコメントを統合
          $content = $content . '<br /><br />' . $link . '<br /><br />' . $comments;

          $post_info = array(
            'title' => $title,
            'published_date' => $published_date_gmt,
            'updated_date' => $updated_date,
            'attachment_url' => $link,
            'content' => $content,
            'comment' => $comments,
            'id' => $post_id,
          );

          // リストに追加
          $post_data_list[] = $post_info;
        }
      }
    }
  }
  return $post_data_list;
}

function post_2_blog($post_data_list){
  global $hist_file;
  global $domain;
  global $xmlrpc_path;
  global $user;
  global $pass;
  global $pubcert_path;
  global $privatekey_path;
  global $sec_pass;
  global $cacert_path;

  foreach ($post_data_list as $post_data) {
    $client = new IXR_ClientSSL($domain, $xmlrpc_path);

    // SSLのための設定
    $client->setCertificate($pubcert_path, $privatekey_path, $sec_pass);
    $client->setCACertificate($cacert_path);

    $status = $client->query(
      "wp.newPost", //使うAPIを指定
      1,     // blog ID: 通常は１、
      $user, // ユーザー名
      $pass, // パスワード
      array(
        'post_author' => $user, // 投稿者ID 未設定の場合投稿者名なしになる。
        'post_status' => 'draft', // 投稿状態（draftにすると下書きにできる）
        'post_title' => $post_data['title'], // タイトル
        'post_content' => $post_data['content'], // 本文
        'post_date_gmt' => $post_data['published_date'], // 投稿の作成日時。
        'comment_status' => 'open', //コメントを許可
      )
    );

    if(!$status){
      // 投稿失敗時に、エラーメッセージ・コードを出力
        echo $client->getErrorCode().' : '.$client->getErrorMessage() . "]\n";
        echo 'Post failed : ' . $post_data['title'] . "\n";
    } else {
      $postid = $client->getResponse(); // 戻り値は投稿ID
      echo 'Successfully posted : ' . $post_data['title'] . "\n";

      // txtファイルに書き込む
      $export = fopen($hist_file, 'a+b');
      fwrite($export, $post_data['id'] . "\n");
      fclose($export);
    }
  }
}

function main(){
  global $json_files_path;

  $post_data_dir = $json_files_path;
  // $post_data_dir = mb_convert_encoding($post_data_dir, 'UTF-8');

  echo "Start fetching...\n";

  // jsonデータから投稿データを取得
  $post_data_list = get_data($post_data_dir);
  // var_dump($post_data_list);

  echo "Start posting...\n";

  // wordpressに投稿
  post_2_blog($post_data_list);

  echo "Done.\n";

}

main();
?>
