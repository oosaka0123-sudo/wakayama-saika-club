<?php
/** LINEの自動返信文（必要最低限）。文面の変更はこのファイルだけで行う。 */
declare(strict_types=1);
if (!defined('WSC_APP')) { http_response_code(403); exit; }

const WSC_CMD_SUBMIT = ['送信', '送信する', '完了', '投稿', '投稿する', 'おわり', '終わり', '以上'];
const WSC_CMD_CANCEL = ['取消', '取り消し', '取り消す', 'キャンセル', 'やり直し', 'やめる'];
const WSC_SITE_CATCH_URL = 'https://oosaka0123-sudo.github.io/wakayama-saika-club/catch/';
const WSC_CMD_HELP = ['使い方', 'ヘルプ', 'help', '？', '?'];

/** クイック返信は「送信する」ボタン1つだけ（高齢の会員が迷わないように）。取消は文字で「取消」。 */
function wsc_quick_submit(): array { return ['送信する' => '送信']; }

function wsc_msg_welcome(): array
{
    return [wsc_text_message(
        "和歌山雑賀クラブ 釣果投稿LINEへようこそ！\n\n" .
        "【1】最初の1回だけ\nクラブから聞いた「合言葉」を、そのまま送ってください。\n\n" .
        "【2】釣果を送る\n① お名前を送る（なくてもOK）\n② 釣果の写真を送る\n③ コメントを送る\n　（なければ、下の「送信する」を押す）\n\n" .
        "送ると、ホームページの「釣果情報」に載ります。"
    )];
}

function wsc_msg_help(): array
{
    return [wsc_text_message(
        "【釣果の送り方】\n" .
        "① お名前を送る（なくてもOK）\n　→「○○さん、こんにちは！」と返ってきたら、次は写真です。\n" .
        "② 釣果の写真を送る（5枚まで）\n" .
        "③ コメントを送る\n　（なければ「送信する」を押す）\n\n" .
        "※ 写真がないと載りません。\n※ やり直すときは「取消」と送ってください。\n※ 初めての方は、合言葉だけを送ってください。"
    )];
}

/** 写真を受け取るたびに毎回返す（LINEのクイック返信は次の発言で消えるため、ボタンを付け直す）。 */
function wsc_msg_photo_received(): array
{
    return [wsc_text_message(
        "写真を受け取りました。\nコメントがあれば入力してください。\nなければ下の『送信する』を押してください。",
        wsc_quick_submit()
    )];
}

/** 名前を先に送ったとき。 */
function wsc_msg_name_greeting(string $name): array
{
    return [wsc_text_message($name . "さん、こんにちは！\n釣果の写真を送ってください。")];
}

/** 写真より先に文章だけが届いたとき（写真がないと掲載しない）。 */
function wsc_msg_text_wait_photo(): array
{
    return [wsc_text_message("文章を受け取りました。\n続けて、釣果の写真を送ってください。\n（写真がないと載りません）")];
}

function wsc_msg_need_photo(): array
{
    return [wsc_text_message("写真がまだ届いていません。\n釣果の写真を送ってください。\n（写真がないと載りません）")];
}

function wsc_msg_published(): array
{
    return [wsc_text_message("ありがとうございます！釣果情報を掲載しました。\n" . WSC_SITE_CATCH_URL)];
}

function wsc_msg_already_published(): array
{
    return [wsc_text_message("すでに掲載ずみです。ありがとうございます！\n" . WSC_SITE_CATCH_URL)];
}

function wsc_msg_already_received(): array
{
    return [wsc_text_message("すでに受け付けています。\n管理者の確認後、ホームページに掲載します。")];
}

/** 自動掲載を停止中・上限超過などで承認待ちになったとき。 */
function wsc_msg_received_pending(): array
{
    return [wsc_text_message(
        "ありがとうございます！釣果情報を受け付けました。\n\n管理者の確認後、ホームページの『釣果情報』に掲載します。"
    )];
}

function wsc_msg_need_registration(): array
{
    return [wsc_text_message(
        "最初の1回だけ会員登録が必要です。\nクラブから聞いた合言葉を、そのまま送ってください。\n\n※登録前の写真・文章は保存されません。"
    )];
}

function wsc_msg_registered(): array
{
    return [wsc_text_message(
        "登録できました！\n\nお名前を送ると、名前つきで載ります（なくてもOK）。\nそのまま釣果の写真を送ってもかまいません。"
    )];
}

function wsc_msg_wrong_passphrase(bool $locked): array
{
    return [wsc_text_message($locked
        ? '合言葉の入力に続けて失敗したため、本日は登録できません。クラブの管理者にお問い合わせください。'
        : '合言葉が違います。クラブから案内された合言葉をご確認ください。')];
}

function wsc_msg_registration_closed(): array
{
    return [wsc_text_message('現在、会員登録の受付を準備中です。クラブの管理者にお問い合わせください。')];
}

function wsc_msg_blocked(): array
{
    return [wsc_text_message('現在このアカウントからの投稿は停止されています。クラブの管理者にお問い合わせください。')];
}

function wsc_msg_too_fast(): array
{
    return [wsc_text_message('短時間に多くの操作があったため、しばらく受付を停止しています。少し時間をおいてから送ってください。')];
}

function wsc_msg_nothing_to_submit(): array
{
    return [wsc_text_message('送信する内容がまだありません。\nまず釣果の写真を送ってください。')];
}

function wsc_msg_cancelled(): array
{
    return [wsc_text_message('送信前の内容を取り消しました。もう一度最初から送ってください。')];
}

function wsc_msg_photo_limit(): array
{
    return [wsc_text_message('写真は5枚までです。6枚目以降は保存していません。\nコメントを送るか、下の『送信する』を押してください。', wsc_quick_submit())];
}

function wsc_msg_photo_rejected(): array
{
    return [wsc_text_message('写真を保存できませんでした（対応していない形式か、サイズが大きすぎます）。別の写真でお試しください。')];
}

function wsc_msg_daily_limit(): array
{
    return [wsc_text_message('本日の投稿数の上限に達しました。明日また送ってください。')];
}

function wsc_msg_unsupported(): array
{
    return [wsc_text_message('このアカウントでは写真と文章のみ受け付けています。')];
}
