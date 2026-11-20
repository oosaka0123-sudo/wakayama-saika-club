<?php
/** LINEの自動返信文（必要最低限）。文面の変更はこのファイルだけで行う。 */
declare(strict_types=1);
if (!defined('WSC_APP')) { http_response_code(403); exit; }

const WSC_CMD_SUBMIT = ['送信', '送信する', '完了', '投稿', '投稿する', 'おわり', '終わり', '以上'];
const WSC_CMD_CANCEL = ['取消', '取り消し', '取り消す', 'キャンセル', 'やり直し', 'やめる'];
const WSC_SITE_CATCH_URL = 'https://oosaka0123-sudo.github.io/wakayama-saika-club/catch/';
const WSC_CMD_HELP = ['使い方', 'ヘルプ', 'help', '？', '?'];

function wsc_quick_submit(): array { return ['送信する' => '送信', '取り消す' => '取消']; }

function wsc_msg_welcome(): array
{
    return [wsc_text_message(
        "和歌山雑賀クラブ 釣果投稿LINEへようこそ！\n\n" .
        "こちらはクラブ会員の釣果投稿専用アカウントです。\n\n" .
        "【最初の1回だけ】\nクラブから聞いた合言葉を、そのまま送ってください。\n\n" .
        "【送り方】\n釣果写真（1〜5枚）とコメントを送り、最後に「送信」と送ってください。投稿者名は任意です。\n\n" .
        "送信すると、ホームページの「釣果情報」に掲載されます。"
    )];
}

function wsc_msg_help(): array
{
    return [wsc_text_message(
        "【釣果の送り方】\n" .
        "① 写真を送る（1〜5枚）\n" .
        "② 必要ならコメントを送る（例）\n" .
        "11月20日\nマダイ 3匹\n今日は朝からよく釣れました！\n投稿者：山田\n" .
        "③ 最後に「送信」と送る → ホームページに掲載\n\n" .
        "※ 投稿者名・魚種・日付は省略できます。\n※ 送信前にやり直すときは「取消」と送ってください。\n※ 初めての方は、合言葉だけを送ってください。",
        wsc_quick_submit()
    )];
}

function wsc_msg_draft_started(): array
{
    return [wsc_text_message(
        "受け取りました。続けて写真（5枚まで）やコメントを送れます。\n送り終わったら「送信する」を押してください。ホームページに掲載されます。",
        wsc_quick_submit()
    )];
}

/** 2通目以降の写真・コメントにもクイック返信を付け直す。LINEは次の発言でボタンを消すため。 */
function wsc_msg_draft_continued(): array
{
    return [wsc_text_message(
        "追加しました。送り終わったら下の「送信する」を押してください。",
        wsc_quick_submit()
    )];
}

function wsc_msg_published(): array
{
    return [wsc_text_message(
        "釣果情報をホームページに掲載しました。\n" . WSC_SITE_CATCH_URL . "\n\nご投稿ありがとうございます！\n内容の訂正・削除は管理者にご連絡ください。"
    )];
}

/** 自動掲載を停止中・上限超過などで承認待ちになったとき。 */
function wsc_msg_received_pending(): array
{
    return [wsc_text_message(
        "釣果情報を受け付けました。\n\n管理者の確認後、ホームページの『釣果情報』に掲載します。\n\nご投稿ありがとうございます！"
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
        "登録できました！\n写真（1〜5枚）とコメントを送り、最後に下の「送信する」を押してください。",
        wsc_quick_submit()
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
    return [wsc_text_message('送信する内容がまだありません。釣果写真かコメントを送ってから「送信」と送ってください。')];
}

function wsc_msg_cancelled(): array
{
    return [wsc_text_message('送信前の内容を取り消しました。もう一度最初から送ってください。')];
}

function wsc_msg_photo_limit(): array
{
    return [wsc_text_message('1回の投稿は写真5枚までです。6枚目以降は保存していません。「送信する」で確定してください。', wsc_quick_submit())];
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
