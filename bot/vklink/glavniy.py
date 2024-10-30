# ENCODING WITH UTF-8
#
#TODO
# возможно сделать /unreport-all,/pardon-all для support для разбана всех по нику

import vk_api
from vk_api.longpoll import VkLongPoll, VkEventType
import random
import sqlite3
import yaml
import os
from datetime import datetime, timedelta
import threading
import mcrcon
import time
import traceback
import requests

print("Работает)")

#===================================#
#                                   #
#           Другое                  #
#                                   #
#===================================#

last_unlink_time = {}

#===================================#
#                                   #
#          Конфигурация             #
#                                   #
#===================================#

config_path = '/root/vklink/config.yml'
with open(config_path, 'r') as file:
    config = yaml.safe_load(file)

access_token = config['access_token']
logs_admin_token = config['logs_admin_token']
rcon_ranks = config['permissions']

#===================================#
#                                   #
# Пути к базам данных и файлам      #
#                                   #
#===================================#

db_path = '/root/vklink/vk_bot.db'
auth_db_path = '/root/linux/plugins/VKAuth/auth.db'
playtime_db_path = '/root/linux/plugins/vkplaytime/playtime.db'
vk_bans_id_path = '/root/linux/plugins/HlebBans_src/vk_id.db'
vk_protect_path = '/root/linux/plugins/vkProtection/data/store.db'

#===================================#
#                                   #
#    Подключение к базам данных     #
#                                   #
#===================================#

# подключение к vk_bot.db
conn = sqlite3.connect(db_path)
cursor = conn.cursor()

# Подключение к базе vk_id.db
conn_bans = sqlite3.connect(vk_bans_id_path)
cursor_bans = conn_bans.cursor()

# Подключение к vkProtection
conn_protect = sqlite3.connect(vk_protect_path)
cursor_protect = conn_protect.cursor()

#===================================#
#                                   #
# Проверка и создание таблиц в базе # #  данных                           #
#                                   #
#===================================#

cursor.execute('''
    CREATE TABLE IF NOT EXISTS vk_links (
        username TEXT UNIQUE,
        vk_id INTEGER,
        vk_code TEXT,
        link TEXT DEFAULT 'NO',
        process TEXT DEFAULT 'pending',
        last_ip TEXT
    )
''')

# Добавление новых полей в таблицу vk_rcon
cursor.execute('''
    CREATE TABLE IF NOT EXISTS vk_rcon (
        nickname TEXT UNIQUE,
        vk_id INTEGER,
        rank TEXT,
        banned TEXT DEFAULT 'NO',
        ban_reason TEXT,
        ban_time TEXT,
        selected_server TEXT
    )
''')

# добавление новых полей в таблицу others
cursor.execute('''
    CREATE TABLE IF NOT EXISTS others (
        vk_id INTEGER UNIQUE,
        last_reset_time TEXT,
        selected_account TEXT
    )
''')
# добавление новых полей в таблицу settings
cursor.execute('''CREATE TABLE IF NOT EXISTS settings (
    nickname TEXT,
    vk_id INTEGER NOT NULL,
    mailing TEXT DEFAULT 'YES',
    twofa_active TEXT DEFAULT 'NO'
)''')

conn.commit()

#===================================#
#                                   #
#      Авторизация ВКонтакте        #
#                                   #
#===================================#

vk_session = vk_api.VkApi(token=access_token)
longpoll = VkLongPoll(vk_session)
vk = vk_session.get_api()

#===================================#
#                                   #
# Авторизация сообщества FallCraft  # # Logs                              # #                                   #
#===================================#

# Создаем сессию для работы с токеном пользователя (токен администратора)
vk_session_user = vk_api.VkApi(token=logs_admin_token)
vk_user = vk_session_user.get_api()

#===================================#
#                                   #
#Основная функция отправки сообщений#
#                                   #
#===================================#

def send_vk_message(user_id, message):
    vk.messages.send(
        user_id=user_id,
        message=message,
        random_id=random.randint(1, 1e6)
    )

def send_vk_reply_message(user_id, message_id, message):
    vk.messages.send(
        peer_id=user_id,
        message=message,
        reply_to=message_id,
        random_id=random.randint(1, 1e6)
    )

#===================================#
#                                   #
#          Функции бота             #
#                                   #
#===================================#

def get_playtime(username):
    try:
        conn_playtime = sqlite3.connect(playtime_db_path)
        cursor_playtime = conn_playtime.cursor()
        cursor_playtime.execute("SELECT time FROM players WHERE username = ?", (username,))
        row = cursor_playtime.fetchone()
        conn_playtime.close()
        
        if row:
            seconds = row[0]
            hours, remainder = divmod(seconds, 3600)
            minutes, _ = divmod(remainder, 60)
            return hours, minutes
    except Exception as e:
        print(f"Ошибка при получении времени игры для пользователя {username}: {e}")
    return 0, 0

def get_last_session(username):
    try:
        conn_playtime = sqlite3.connect(playtime_db_path)
        cursor_playtime = conn_playtime.cursor()
        cursor_playtime.execute("SELECT last_session FROM players WHERE username = ?", (username,))
        row = cursor_playtime.fetchone()
        conn_playtime.close()
        
        if row:
            seconds = row[0]
            minutes, _ = divmod(seconds, 60)
            return minutes
    except Exception as e:
        print(f"Ошибка при получении последней сессии для пользователя {username}: {e}")
    return 0

def get_last_date(username):
    try:
        conn_playtime = sqlite3.connect(playtime_db_path)
        cursor_playtime = conn_playtime.cursor()
        cursor_playtime.execute("SELECT date FROM players WHERE username = ?", (username,))
        row = cursor_playtime.fetchone()
        conn_playtime.close()
        
        if row:
            return row[0]
    except Exception as e:
        print(f"Ошибка при получении последней даты для пользователя {username}: {e}")
    return 'Неизвестно'
    
#===================================#
#                                   #
#     Основная RCON функция         #
#                                   #
#===================================#

def execute_rcon_command(user_id, message_id, command, *args):
    try:
        selected_account = get_selected_account(user_id)
        selected_server = get_selected_server(selected_account)
        
        if not selected_account:
            send_vk_reply_message(user_id, message_id, "🚫 | У вас не выбран аккаунт для выполнения команды.")
            return

        if not selected_server:
            send_vk_reply_message(user_id, message_id, "🚫 | Вы не выбрали сервер для подключения. \n » /rcon выбрать [имя сервера].")
            return

        # Получаем данные выбранного сервера из конфига
        server_config = config['servers'].get(selected_server)
        if not server_config:
            send_vk_reply_message(user_id, message_id, "🚫 | Некорректный выбор сервера.")
            return

        # Соединяемся с сервером через RCON
        full_command = f"{command} " + " ".join(args)
        with mcrcon.MCRcon(server_config['rcon_host'], server_config['rcon_password'], port=server_config['rcon_port']) as mcr:
            response = mcr.command(full_command)

        if response.strip():  # Проверяем, что ответ не пустой или не состоит из одних пробелов
            send_vk_reply_message(user_id, message_id, f"✅ | Вы успешно отправили команду на сервер {selected_server} (by {selected_account}) :\n {response}")
        else:
            # Сообщение, если сервер вернул пустой ответ
            send_vk_reply_message(user_id, message_id, f"✅ | Вы успешно отправили команду на сервер {selected_server} (by {selected_account}) :\n📩 | сервер вернул пустой ответ")
    
    except Exception as e:
        send_vk_reply_message(user_id, message_id, f"🚫 | Ошибка при выполнении команды ({selected_server}): \n{str(e)}")

#rcon_ranks = config['permissions']

def get_rank_permissions(rank):
    if rank in rcon_ranks:
        allowed_commands = rcon_ranks[rank]
        if '*' in allowed_commands:
            return '*'
        return allowed_commands
    return []

def get_server_permissions(rank):
    """Получаем список серверов, доступных для ранга пользователя."""
    return config['serv_perms'].get(rank, [])

def get_selected_server(selected_account):
    """Получаем выбранный сервер пользователя из базы данных."""
    #selected_account = get_selected_account(user_id)
    
    cursor.execute("SELECT selected_server FROM vk_rcon WHERE nickname = ?", (selected_account,))
    row = cursor.fetchone()
    return row[0] if row else None

def select_server(server_name, user_id):
    """Сохраняем выбор сервера в базе данных."""
    selected_account = get_selected_account(user_id)
    
    cursor.execute("UPDATE vk_rcon SET selected_server = ? WHERE nickname = ?", (server_name, selected_account))
    conn.commit()

def ban_player(username, ban_reason, ban_duration_hours, user_id):
    ban_time = datetime.now() + timedelta(hours=ban_duration_hours)
    ban_time_str = ban_time.strftime("%Y-%m-%d %H:%M:%S")

    # Обновляем информацию о бане в базе данных с причиной, где пробелы заменены на подчеркивания
    cursor.execute("UPDATE vk_rcon SET banned = 'YES', ban_reason = ?, ban_time = ? WHERE nickname = ?", (ban_reason, ban_time_str, username))
    conn.commit()

    # Получаем vk_id игрока и отправляем личное сообщение
    cursor.execute("SELECT vk_id FROM vk_rcon WHERE nickname = ?", (username,))
    row = cursor.fetchone()
    display_ban_reason = ban_reason.replace('_', ' ')
    if row:
        vk_id = row[0]
        # Замена подчеркиваний на пробелы при выводе
        #display_ban_reason = ban_reason.replace('_', ' ')
        send_vk_message(vk_id, f"🚫 | [id{vk_id}|{username}], вы были заблокированы в консоли сервера по причине: {display_ban_reason}. Разбан через: {ban_duration_hours} час(ов).")

    # Получаем никнейм администратора
    selected_account = get_selected_account(user_id)
    cursor.execute("SELECT nickname FROM vk_rcon WHERE nickname = ?", (selected_account,))
    admin_row = cursor.fetchone()
    admin_nick = admin_row[0] if admin_row else "Администратор"  # Если никнейм не найден, используем дефолтное значение

    # Отправляем сообщение в комментарии обсуждения через токен пользователя
    vk_user.board.createComment(
        group_id=config['logs_group_id'],  # ID группы (без минуса)
        topic_id=config['logs_topic_id'],
        from_group=config['from_group'],
        message=f"⚠️ | На игрока [id{vk_id}|{username}] наложено ограничение донатерских возможностей и блокировка консоли игроком [id{user_id}|{admin_nick}].\n\n » Причина: {display_ban_reason}\n » Длительность: {ban_duration_hours} ч."
    )

def unban_player(username, user_id=None):
    # Обновляем статус игрока в базе данных
    cursor.execute("UPDATE vk_rcon SET banned = 'NO', ban_reason = NULL, ban_time = NULL WHERE nickname = ?", (username,))
    conn.commit()

    # Получаем vk_id игрока и отправляем сообщение о разбане
    cursor.execute("SELECT vk_id FROM vk_rcon WHERE nickname = ?", (username,))
    row = cursor.fetchone()
    if row:
        vk_id = row[0]
        send_vk_message(vk_id, "✅ | Вы были разбанены в консоли сервера.")

        # Проверяем, кто выполняет разбан: бот или администратор
        if user_id is None:
            admin_nick = "Сервером"  # Если разбан выполняется ботом, указываем "Сервером"
        else:
            # Получаем никнейм администратора, если разбанил администратор
            selected_account = get_selected_account(user_id)
            cursor.execute("SELECT nickname FROM vk_rcon WHERE nickname = ?", (selected_account,))
            admin_row = cursor.fetchone()
            if admin_row:
                admin_nick = admin_row[0]
            else:
                admin_nick = "Администратор"  # Если никнейм не найден, используем дефолтное значение

        # Отправляем сообщение в комментарии обсуждения через токен пользователя
        vk_user.board.createComment(
            group_id=config['logs_group_id'],  # ID группы (без минуса)
            topic_id=config['logs_topic_id'],
            from_group = config['from_group'],
            message=f"⚠️ | С игрока [id{vk_id}|{username}] сняты все ограничения игроком [id{user_id}|{admin_nick}]."
        )

# Функция для получения никнейма из сообщения
def get_username_from_message(message_text):
    try:
        _, username = message_text.split(' ', 1)
        return username.strip()
    except ValueError:
        return None

# Функция для получения данных пользователя из таблицы vk_links
def get_user_data(username):
    cursor.execute("SELECT vk_id, link FROM vk_links WHERE username = ?", (username,))
    user_row = cursor.fetchone()
    if user_row and user_row[1] == 'YES':  # Только если аккаунт привязан
        return {'vk_id': user_row[0], 'username': username}
    return None

# Функция для получения данных из vk_rcon
def get_rcon_data(nickname):
    cursor.execute("SELECT rank, banned, ban_reason, ban_time FROM vk_rcon WHERE nickname = ?", (nickname,))
    rcon_row = cursor.fetchone()
    if rcon_row:
        return {
            'rank': rcon_row[0] or "Нету",
            'banned': rcon_row[1] or "NO",
            'ban_reason': rcon_row[2] or "Нету",
            'ban_time': rcon_row[3] or "Нету"
        }
    return None

# Форматирование сообщения с данными о пользователе
def format_player_info_message(username, vk_id, rcon_data, play_time):
    if rcon_data is None:
        # Если данных о пользователе нет, выводим значения по умолчанию
        rank = "Нету"
        banned = "Нету"
        ban_reason = "Нету"
        ban_time = "Нету"
    else:
        # Если данные есть, извлекаем их
        rank = rcon_data.get('rank', 'Неизвестно')
        banned = rcon_data.get('banned', 'Неизвестно')
        ban_reason = rcon_data.get('ban_reason', 'Не указана')
        ban_time = rcon_data.get('ban_time', 'Не указано')

    # Получаем список привязанных аккаунтов
    cursor.execute("SELECT username FROM vk_links WHERE vk_id = ?", (vk_id,))
    accounts = cursor.fetchall()
    accounts_list = ', '.join([account[0] for account in accounts]) if accounts else "Нет привязанных аккаунтов"

    hours, minutes = play_time

    # Проверяем, является ли пользователь администратором из config.yml
    is_admin = "Да" if vk_id in config['admins'] else "Нет"

    # Формируем итоговое сообщение
    player_info_message = f"""📝 | Найдена инфа по нику: {username}\n\n🔰 | ВК ID: {vk_id}\n🔑 | Доступ: {rank}\n🕹 | Всего наиграно: {hours} ч. {minutes} м.\n⛓️ | Привязанные акки: {accounts_list}\n🚫 | Бан в консоли: \n» Забанен: {banned}\n» Причина: {ban_reason}\n» Длительность: до {ban_time} MSK\n👑 | Выш.админ: {is_admin}"""
    return player_info_message

def get_selected_account(vk_id):
    try:
        # Подключаемся к базе данных
        conn = sqlite3.connect('vk_bot.db')
        cursor = conn.cursor()

        # Выполняем запрос к таблице others, чтобы получить поле selected_account
        cursor.execute("SELECT selected_account FROM others WHERE vk_id = ?", (vk_id,))
        row = cursor.fetchone()

        # Если данные найдены, возвращаем selected_account
        if row:
            selected_account = row[0]
            return selected_account
        else:
            # Если данных нет, возвращаем None
            return None

    except sqlite3.Error as e:
        print(f"Ошибка при работе с базой данных: {e}")
        return None

    finally:
        # Закрываем соединение с базой данных
        if conn:
            conn.close()

#===================================#
#                                   #
#    Основные обработчики команд    #
#                                   #
#===================================#

def run_bot():
    try:
        for event in longpoll.listen():
            if event.type == VkEventType.MESSAGE_NEW and event.to_me:
                user_id = event.user_id
                message = event.text.strip()
                message_text = event.text.strip()
                message_id = event.message_id

                if message_text.startswith('/привязать'):
                    # Проверка на то, что прошло 30 минут с момента последней отвязки
                    if user_id in last_unlink_time:
                        elapsed_time = time.time() - last_unlink_time[user_id]
                        if elapsed_time < 1800:  # 1800 секунд = 30 минут
                            remaining_time = 30 - (elapsed_time // 60)
                            send_vk_reply_message(user_id, message_id, f"🚫 | Чтоб снова привязать аккаунт нужно подождать {remaining_time} минут.")
                            continue

                    try:
                        _, data = message_text.split(' ', 1)
                        username, vk_code = data.split(' ')
                    except ValueError:
                        send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте /привязать [ник] [код].")
                        continue

                    # Проверяем, является ли пользователь администратором
                    is_admin = user_id in config['admins']

                    # Ограничение на количество привязанных аккаунтов
                    cursor.execute("SELECT COUNT(*) FROM vk_links WHERE vk_id = ?", (user_id,))
                    account_count = cursor.fetchone()[0]
                    max_accounts = 5 if is_admin else 3

                    if account_count >= max_accounts:
                        send_vk_reply_message(user_id, message_id, f"🚫 | Вы достигли лимита на привязку аккаунтов ({max_accounts} акков).")
                        continue

                    # Проверка, привязан ли этот никнейм к текущему профилю
                    cursor.execute("SELECT * FROM vk_links WHERE username = ? AND vk_id = ?", (username, user_id))
                    row = cursor.fetchone()
                    if row:
                        send_vk_reply_message(user_id, message_id, f"🚫 | Вы уже привязали аккаунт {username} к этому профилю.")
                        continue

                    # Проверка, привязан ли этот никнейм к другому профилю
                    cursor.execute("SELECT * FROM vk_links WHERE username = ? AND vk_id IS NOT NULL", (username,))
                    row = cursor.fetchone()
                    if row:
                        send_vk_reply_message(user_id, message_id, f"🚫 | Этот аккаунт {username} уже привязан к другому профилю!")
                        continue

                    # Проверка на правильность никнейма и кода
                    cursor.execute("SELECT * FROM vk_links WHERE username = ? AND vk_code = ?", (username, vk_code))
                    row = cursor.fetchone()

                    if row:
                        cursor_bans.execute("INSERT INTO users (username, vk_id) VALUES (?, ?)", (username, user_id))
                        conn_bans.commit()

                        cursor.execute('''
                            UPDATE vk_links SET vk_id = ?, link = 'YES' 
                            WHERE username = ? AND vk_code = ?
                        ''', (user_id, username, vk_code))
                        conn.commit()
                        send_vk_reply_message(user_id, message_id, f"✅ Вы успешно привязали аккаунт '{username}' к своему профилю!\n\n🔒 Теперь ваш аккаунт находится под нашей защитой\n\n🙏 Будем благодарны, если Вы пожертвуете нам на развитие проекта, купив привилегию - https://fallcraft.ru/")
                    else:
                        send_vk_reply_message(user_id, message_id, "🚫 | Неверный ник или код. Проверьте правильность введенных данных.\n (попробуйте в нижнем регистре)")

                elif message_text == '/отвязать':
                    # Получение выбранного аккаунта через функцию
                    selected_account = get_selected_account(user_id)

                    if selected_account:
                        cursor.execute("SELECT * FROM vk_links WHERE vk_id = ? AND username = ?", (user_id, selected_account))
                        if cursor.fetchone():
                            send_vk_reply_message(user_id, message_id, f"😨 | Чтобы отвязать аккаунт {selected_account}, вы должны подтвердить свое действие\n\n✏️ | Для этого напишите в чат 'ПОДТВЕРЖДАЮ' заглавными буквами\n\n❗️ Внимание, данное действие является необратимым! После удаления своего аккаунта, вы не сможете привязать новый аккаунт в течение 30 минут\n(p.s а также доступ к консоли)")
                            
                            # Ожидание подтверждения
                            confirmation_received = False
                            for confirmation_event in longpoll.listen():
                                if confirmation_event.type == VkEventType.MESSAGE_NEW and confirmation_event.to_me and confirmation_event.user_id == user_id:
                                    if confirmation_event.text.strip() == 'ПОДТВЕРЖДАЮ':
                                        cursor.execute("DELETE FROM vk_links WHERE vk_id = ? AND username = ?", (user_id, selected_account))
                                        # Удаление из vk_rcon
                                        cursor.execute("DELETE FROM vk_rcon WHERE vk_id = ? AND nickname = ?", (user_id, selected_account))
                                        conn.commit()
                                        cursor_bans.execute("DELETE FROM users WHERE vk_id = ? AND username = ?", (user_id, selected_account))
                                        conn_bans.commit()
                                        send_vk_reply_message(user_id, message_id, f"😨 | Аккаунт {selected_account} успешно отвязан от вк.")
                                        # Сохранение времени отвязки
                                        last_unlink_time[user_id] = time.time()
                                        confirmation_received = True
                                        break
                                    else:
                                        send_vk_reply_message(user_id, message_id, "✅ | Вы отменили процесс отвязки.")
                                        confirmation_received = True
                                        break
                        else:
                            send_vk_reply_message(user_id, message_id, f"🚫 | Аккаунт {selected_account} не найден.")
                    else:
                        send_vk_reply_message(user_id, message_id, "🚫 | Вы не выбрали аккаунт для отвязки. Используйте /аккаунт выбрать.")

                elif message_text == 'помощь':
                    help_message = (
                        "📰 | Доступные команды:\n"
                        "❤ | /привязать [ник] [код] - Привязать аккаунт.\n"
                        "💔 | /отвязать - Отвязать аккаунт.\n"
                        "🎗 | /аккаунт [аргументы] - управление привязанными аккаунтами.\n » список - все привязанные аккаунты.\n » выбрать - выбор аккаунта для взаимодействия с ботом.\n » профиль - посмотреть профиль выбранного аккаунта.\n » восстановить [новый_пароль] - восстановление пароля.\n » принять - принимает запрос 2FA.\n » отклонить - отклоняет запрос 2FA.\n"
                        "⚙️ | /настройки - управление настройками аккаунта.\n"
                        "📰 | помощь - список всех команд"
                    )
                    send_vk_reply_message(user_id, message_id, help_message)
                if message_text == 'помощь админ':
                    # Проверка ранга пользователя в таблице vk_rcon
                    selected_account = get_selected_account(user_id)
                    cursor.execute("SELECT rank FROM vk_rcon WHERE nickname = ?", (selected_account,))
                    row = cursor.fetchone()

                    if row:
                        user_rank = row[0]  # Извлекаем ранг из результата запроса

                        # Список разрешенных рангов
                        allowed_ranks = ['Helper', 'Moderator', 'SuperAdmin', 'SeniorAdmin', 'Administrator', 'Deputy', 'Support', 'Manager']

                        if user_rank in allowed_ranks:
                            # Если ранг пользователя есть в списке разрешенных рангов, показываем команды
                            admin_help_message = (
                                "🖥 | Админские команды:\n"
                                "⛓️ | /report - заблокировать доступ к консоли и ограничить его донатерские возможности.\n"
                                "(от Administrator)\n"
                                "📩 | /rcon - отправить Rcon команду на сервер.\n» сервера - покажет все доступные сервера.\n» выбрать - выбор нужного сервера.\n"
                                "(от Helper)\n"
                                "📦 |/юзер-лист [номер страницы] - список аккаунтов с доступом.\n"
                                "(от Support)\n"
                                "📤 | /рассылка - разослать всем привязанным аккаунтам сообщение о чем-либо.\n"
                                "(только выш.адм)\n"
                                "👑 | /gp - выдать/изменить текущий ранг пользователя.\n"
                                "(только выш.адм)\n"
                                "💠 | /vk-info [ник] - информация об данном игроке в системе.\n"
                                "(от Helper)"
                            )
                            send_vk_reply_message(user_id, message_id, admin_help_message)
                        else:
                            # Если ранг не соответствует, выводим сообщение о недостатке прав
                            send_vk_reply_message(user_id, message_id, "🚫 | У вас недостаточно прав для просмотра админ команд.")
                    else:
                        # Если данных о пользователе нет в таблице vk_rcon
                        send_vk_reply_message(user_id, message_id, "🚫 | У вас нет прав для просмотра админ команд.")

                elif message_text.startswith('/gp'):
                    # Проверяем, является ли пользователь администратором
                    if user_id not in config['admins']:
                        send_vk_message(user_id, "🚫 | У вас нет прав для использования этой команды.")
                        continue

                    try:
                        _, data = message_text.split(' ', 1)
                        username, rank = data.split(' ')
                    except ValueError:
                        send_vk_message(user_id, "🚫 | Неверный формат.\n » /gp [ник] [ранг].")
                        continue

                    # Список допустимых рангов
                    valid_ranks = ['Нету', 'Developer','GlConsole','Console','Helper', 'Moderator', 'Administrator', 'SeniorAdmin', 'Deputy', 'Support', 'Sponsor', 'Manager', 'SuperAdmin']

                    # Проверка, что указанный ранг допустим
                    if rank not in valid_ranks:
                        send_vk_message(user_id, f"🚫 | Неверный ранг. Доступные ранги: {', '.join(valid_ranks)}.")
                        continue

                    # Проверяем, привязан ли ник к VK ID и завершена ли привязка
                    cursor.execute("SELECT vk_id FROM vk_links WHERE username = ? AND link = 'YES'", (username,))
                    vk_id_row = cursor.fetchone()
                    #selected_server = get_selected_server(selected_account)

                    if vk_id_row:
                        vk_id = vk_id_row[0]
                        cursor.execute("REPLACE INTO vk_rcon (nickname, vk_id, rank) VALUES (?, ?, ?)", (username, vk_id, rank))
                        conn.commit()
                        send_vk_reply_message(user_id, message_id, f"✅ | Ранг {rank} успешно установлен для [id{vk_id}|{username}].")
                    else:
                        send_vk_message(user_id, "🚫 | Ник не привязан или привязка не завершена.")

                elif message_text.startswith('/rcon'):
                    try:
                        command_parts = message_text.split()

                        # Если это просто команда /rcon без подкоманд
                        if len(command_parts) == 1:
                            send_vk_reply_message(user_id, message_id, "Шкибиди доп доп доп ес ес шкибеде доп доп\nhttps://m.youtube.com/watch?v=bagAoB4o6Os&pp=ygUwZXZlcnlib2R5IHdhbnRzIHRvIHJ1bGUgdGhlIHdvcmxkIHNraWJpZGkgdG9pbGV0")
                            continue

                        # Подкоманда /rcon выбрать [имя сервера]
                        if command_parts[1] == "выбрать":
                            if len(command_parts) < 3:
                                send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте: /rcon выбрать [имя сервера].")
                                continue

                            server_name = command_parts[2]
                            selected_account = get_selected_account(user_id)
                            
                            if not selected_account:
                                send_vk_reply_message(user_id, message_id, "🚫 | У вас не выбран аккаунт для выполнения команды.")
                                continue

                            # Проверяем права пользователя на выбор сервера
                            cursor.execute("SELECT rank FROM vk_rcon WHERE nickname = ?", (selected_account,))
                            user_rank = cursor.fetchone()[0]
                            
                            allowed_servers = get_server_permissions(user_rank)
                            
                            if server_name not in allowed_servers:
                                send_vk_reply_message(user_id, message_id, f"🚫 | Сервер {server_name} недоступен для вашему рангy.")
                            else:
                                # Сохраняем выбранный сервер
                                select_server(server_name, user_id)
                                send_vk_reply_message(user_id, message_id, f"🖥 Вы успешно выбрали сервер {server_name} с аккаунта {selected_account}")

                        # Подкоманда /rcon сервера
                        elif command_parts[1] == "сервера":
                            selected_account = get_selected_account(user_id)
                            
                            if not selected_account:
                                send_vk_reply_message(user_id, message_id, "🚫 | У вас не выбран аккаунт для выполнения команды.")
                                continue

                            cursor.execute("SELECT rank FROM vk_rcon WHERE nickname = ?", (selected_account,))
                            user_rank = cursor.fetchone()[0]

                            allowed_servers = get_server_permissions(user_rank)

                            if allowed_servers:
                                message = "🖥 | Доступные сервера:\n"
                                for i, server in enumerate(allowed_servers, start=1):
                                    message += f"✨ {i}. {server}\n"
                                send_vk_reply_message(user_id, message_id, message)
                            else:
                                send_vk_reply_message(user_id, message_id, f"🚫 | {selected_account}, у вас нет доступа к серверам.")

                        # Если это RCON команда
                        else:
                            try:
                                _, command_with_args = message_text.split(' ', 1)  # Получаем команду и аргументы
                                command_parts = command_with_args.split()  # Разделяем команду и аргументы
                                command = command_parts[0]  # Основная команда
                                arguments = command_parts[1:]  # Остальные аргументы

                                # Проверка на выбранный аккаунт из базы данных
                                cursor.execute("SELECT selected_account FROM others WHERE vk_id = ?", (user_id,))
                                row = cursor.fetchone()
                                if row and row[0]:
                                    selected_account = row[0]  # Получаем значение selected_account из БД
                                else:
                                    send_vk_reply_message(user_id, message_id, "🚫 | Вы не выбрали аккаунт для выполнения ркон команды. Используйте /аккаунт выбрать.")
                                    continue

                                cursor.execute("SELECT rank, banned, ban_reason, ban_time, nickname FROM vk_rcon WHERE vk_id = ? AND nickname = ?", (user_id, selected_account))
                                rcon_row = cursor.fetchone()

                                if rcon_row:
                                    rank, banned, ban_reason, ban_time, nickname = rcon_row

                                    # Проверяем, забанен ли пользователь
                                    if banned == 'YES':
                                        remaining_time = datetime.strptime(ban_time, "%Y-%m-%d %H:%M:%S") - datetime.now()
                                        if remaining_time.total_seconds() > 0:
                                            years, remainder = divmod(remaining_time.total_seconds(), 31536000)
                                            months, remainder = divmod(remainder, 2592000)
                                            days, remainder = divmod(remainder, 86400)
                                            hours, remainder = divmod(remainder, 3600)
                                            minutes, _ = divmod(remainder, 60)

                                            ban_duration = []
                                            if years > 0:
                                                ban_duration.append(f"{int(years)} год(а/лет)")
                                            if months > 0:
                                                ban_duration.append(f"{int(months)} месяц(а/ев)")
                                            if days > 0:
                                                ban_duration.append(f"{int(days)} день/дней")
                                            if hours > 0:
                                                ban_duration.append(f"{int(hours)} час(ов)")
                                            if minutes > 0:
                                                ban_duration.append(f"{int(minutes)} минут")

                                            remaining_time_formatted = ', '.join(ban_duration)
                                            
                                            send_vk_reply_message(user_id, message_id, f"🚫 | [id{user_id}|{selected_account}], вы были заблокированы в консоли сервера. по причине: {ban_reason}. разбан через: {remaining_time_formatted}.")
                                            continue
                                        else:
                                            # Если время бана истекло, разбаниваем игрока автоматически
                                            unban_player(selected_account)

                                    allowed_commands = get_rank_permissions(rank)

                                    if '*' in allowed_commands or command in allowed_commands:
                                        if command == "hban":
                                            if len(arguments) >= 3:
                                                target_nickname = arguments[0]
                                                duration = arguments[1]
                                                ban_reason = ' '.join(arguments[2:])

                                                # Добавляем (by {selected_account}) в конец причины бана
                                                ban_reason_with_account = f"{ban_reason} (by {selected_account})"
                                                # Выполнение команды бана с обновленной причиной
                                                execute_rcon_command(user_id, message_id, "hban", target_nickname, duration, ban_reason_with_account)
                                                #send_vk_reply_message(user_id, message_id  f"⛓️ | Игрок {target_nickname} был забанен на {duration} минут по причине: {ban_reason_with_account}.")
                                            else:
                                                send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте: /rcon hban [ник] [время] [причина].")
                                        elif command == "say":
                                            # Создаем сообщение с префиксом и именем пользователя
                                            message = ' '.join(arguments)
                                            formatted_message = f"{message} (by {nickname})"
                                            execute_rcon_command(user_id, message_id, "say", formatted_message)
                                        elif command == "hkick":
                                            if len(arguments) >= 2:
                                                target_nickname = arguments[0]
                                                kick_reason = ' '.join(arguments[1:])

                                                # Добавляем (by {selected_account}) в конец причины кика
                                                kick_reason_with_account = f"{kick_reason} (by {selected_account})"

                                                # Выполнение команды кика с обновленной причиной
                                                execute_rcon_command(user_id, message_id, "hkick", target_nickname, kick_reason_with_account)
                                                #send_vk_reply_message(user_id, message_id  f"⛓️ | Игрок {target_nickname} был кикнут с сервера по причине: {kick_reason_with_account}.")
                                            else:
                                                send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте: /rcon hkick [ник] [причина].")
                                        elif command == "hmute":
                                            if len(arguments) >= 3:
                                                target_nickname = arguments[0]
                                                duration = arguments[1]
                                                mute_reason = ' '.join(arguments[2:])

                                                # Добавляем (by {selected_account}) в конец причины мута
                                                mute_reason_with_account = f"{mute_reason} (by {selected_account})"

                                                # Выполнение команды мута с обновленной причиной
                                                execute_rcon_command(user_id, message_id, "hmute", target_nickname, duration, mute_reason_with_account)
                                                #send_vk_reply_message(user_id, message_id  f"🔇 | Игрок {target_nickname} был замучен на {duration} минут по причине: {mute_reason_with_account}.")
                                            else:
                                                send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте: /rcon hmute [ник] [время] [причина].")
                                        else:
                                            # Выполнение произвольной команды RCON
                                            execute_rcon_command(user_id, message_id ,command, *arguments)
                                    else:
                                        send_vk_reply_message(user_id, message_id, "🚫 | У вас нет прав для выполнения этой команды.")
                                else:
                                    send_vk_reply_message(user_id, message_id, "🚫 | Ваш ркон ранг не установлен или вы не привязаны.")
                            except ValueError:
                                send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте /rcon [команда] [аргументы].")

                    except ValueError:
                        send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат команды.")
                                    

                elif message_text.startswith('/рассылка'):
                    # Проверка на то, что пользователь является администратором
                    if user_id in config['admins']:
                        # Получение текста сообщения для рассылки
                        reply_message_text = event.text.split('/рассылка', 1)[-1].strip()

                        if reply_message_text:
                            # Получение пользователей с рассылкой mailing = YES
                            cursor.execute("""
                                SELECT DISTINCT vk_links.vk_id 
                                FROM vk_links
                                JOIN settings ON vk_links.username = settings.nickname
                                WHERE vk_links.vk_id IS NOT NULL AND settings.mailing = 'YES'
                            """)
                            users = cursor.fetchall()

                            if users:
                                # Отправка сообщения каждому уникальному пользователю
                                for user in users:
                                    vk_id = user[0]
                                    send_vk_message(vk_id, reply_message_text)

                                send_vk_reply_message(user_id, message_id, "✅ | Сообщение успешно отправлено всем пользователям с активированной рассылкой.")
                            else:
                                send_vk_reply_message(user_id, message_id, "🚫 | Нет пользователей с активированной рассылкой.")
                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | Вы не указали сообщение для рассылки.")
                    else:
                        send_vk_reply_message(user_id, message_id, "🚫 | У вас нет прав для выполнения команды.")

                elif message_text.startswith('/report'):
                    try:
                        _, data = message_text.split(' ', 1)

                        # Получаем выбранный аккаунт пользователя
                        selected_account = get_selected_account(user_id)

                        # Проверка забанен ли пользователь
                        cursor.execute("SELECT banned FROM vk_rcon WHERE nickname = ?", (selected_account,))
                        banned_status = cursor.fetchone()

                        if banned_status and banned_status[0] == 'YES':
                            send_vk_message(user_id, f"🚫 | {selected_account}, вы не можете использовать эту команду, так как заблокированы в консоли.")
                            continue

                        # Проверка прав пользователя
                        cursor.execute("SELECT rank FROM vk_rcon WHERE nickname = ?", (selected_account,))
                        user_rank = cursor.fetchone()

                        if not user_rank or user_rank[0] not in ['Helper', 'Moderator', 'Support', 'SuperAdmin', 'Deputy', 'Administrator', 'SeniorAdmin']:
                            send_vk_message(user_id, "🚫 | У вас недостаточно прав для использования этой команды.")
                            continue

                        if data.startswith('-'):
                            # Разбан игрока
                            username = data[1:]  # Убираем минус перед ником

                            # Проверка никнейма на нижний регистр
                            if not username.islower():
                                send_vk_message(user_id, "🚫 | Никнейм игрока должен быть в нижнем регистре.")
                                continue

                            cursor.execute("SELECT banned FROM vk_rcon WHERE nickname = ? AND rank IS NOT NULL", (username,))
                            row = cursor.fetchone()

                            if row:
                                banned_status = row[0]

                                # Проверка находится ли игрок в бане
                                if banned_status == "NO":
                                    send_vk_message(user_id, "🚫 | Игрок не находится в бане.")
                                    continue

                                unban_player(username, user_id)
                                send_vk_message(user_id, f"✅ | Игрок {username} был разбанен в консоли сервера.")
                            else:
                                send_vk_message(user_id, "🚫 | Игрок не привязан к консоли.")
                        else:
                            # Бан игрока
                            components = data.split(' ')
                            username = components[0]
                            ban_reason = '_'.join(components[1:-1])  # Объединяем все части, кроме никнейма и времени, через "_"
                            ban_duration_str = components[-1]
                            ban_duration_hours = int(ban_duration_str)

                            # Проверка никнейма на нижний регистр
                            if not username.islower():
                                send_vk_message(user_id, "🚫 | Никнейм игрока должен быть в нижнем регистре.")
                                continue

                            cursor.execute("SELECT rank FROM vk_rcon WHERE nickname = ? AND rank IS NOT NULL", (username,))
                            row = cursor.fetchone()

                            if row:
                                ban_player(username, ban_reason, ban_duration_hours, user_id)
                                send_vk_message(user_id, f"🚫 | Игрок {username} был заблокирован в консоли сервера.")
                            else:
                                send_vk_message(user_id, "🚫 | Игрок не привязан к консоли.")

                    except ValueError:
                        send_vk_message(user_id, "🚫 | Неверный формат. Используйте /report [ник игрока] [причина] [время в часах].\nили /report [-ник_игрока] для разбана.")

                if message_text.startswith('/аккаунт'):
                    try:
                        _, args = message_text.split(' ', 1)
                    except ValueError:
                        send_vk_reply_message(user_id, message_id, "🚫 не выбраны аргументы. Введите /аккаунт [список, выбрать, профиль, принять, отклонить, восстановить, разбан]")
                        continue

                    if args == "список":
                        # Получаем все привязанные аккаунты пользователя
                        cursor.execute("SELECT username FROM vk_links WHERE vk_id = ?", (user_id,))
                        accounts = cursor.fetchall()

                        # Получаем выбранный аккаунт из таблицы others
                        selected_account = get_selected_account(user_id)

                        if accounts:
                            account_list = "\n".join([
                                f"✨ {i + 1}. {account[0]}{'✅' if account[0] == selected_account else ''}" 
                                for i, account in enumerate(accounts)
                            ])
                            send_vk_reply_message(user_id, message_id, f"🕹 | Ваши привязанные аккаунты:\n{account_list}\n📰 | помощь - помощь")
                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | У вас нет привязанных аккаунтов.")
                    
                    elif args.startswith("выбрать"):
                        try:
                            _, account_number = args.split(' ', 1)
                            account_number = int(account_number) - 1  # Преобразуем номер аккаунта в индекс
                        except ValueError:
                            send_vk_reply_message(user_id, message_id, "🚫 | для выбора аккаунта используйте - /аккаунт выбрать [номер аккаунта]")
                            continue
                        
                        # Получаем список всех аккаунтов
                        cursor.execute("SELECT username FROM vk_links WHERE vk_id = ?", (user_id,))
                        accounts = cursor.fetchall()

                        if 0 <= account_number < len(accounts):
                            selected_account = accounts[account_number][0]

                            # Проверяем, существует ли запись с данным vk_id в таблице others
                            cursor.execute("SELECT 1 FROM others WHERE vk_id = ?", (user_id,))
                            row = cursor.fetchone()

                            if row:
                                # Если запись существует, обновляем поле selected_account
                                cursor.execute("UPDATE others SET selected_account = ? WHERE vk_id = ?", (selected_account, user_id))
                            else:
                                # Если записи нет, вставляем новую запись
                                cursor.execute("INSERT INTO others (vk_id, selected_account) VALUES (?, ?)", (user_id, selected_account))

                            conn.commit()
                            send_vk_reply_message(user_id, message_id, f"✅ | Аккаунт '{selected_account}' успешно выбран для дальнейших действий!")
                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | Некорректный номер аккаунта.")
                    
                    elif args == "профиль":
                        selected_account = get_selected_account(user_id)
                        
                        if selected_account:
                            # Отправляем информацию о выбранном аккаунте
                            cursor.execute("SELECT username, vk_id FROM vk_links WHERE vk_id = ? AND username = ?", (user_id, selected_account))
                            row = cursor.fetchone()

                            if row:
                                username, vk_id = row
                                hours, minutes = get_playtime(username)
                                last_session_minutes = get_last_session(username)
                                last_date = get_last_date(username)

                                auth_conn = sqlite3.connect(auth_db_path)
                                auth_cursor = auth_conn.cursor()
                                auth_cursor.execute("SELECT ip, os, device FROM auth WHERE name = ?", (username,))
                                auth_row = auth_cursor.fetchone()
                                auth_conn.close()

                                if auth_row:
                                    ip, os, device = auth_row
                                else:
                                    ip, os, device = "Неизвестно", "Неизвестно", "Неизвестно"

                                cursor.execute("SELECT rank FROM vk_rcon WHERE nickname = ?", (selected_account,))
                                rcon_row = cursor.fetchone()
                                rank = rcon_row[0] if rcon_row else "Нету"

                                send_vk_reply_message(user_id, message_id, f"📜 | Информация по аккаунту {username}: \n"
                                                          f"🔰 | ВК ID: {vk_id}\n"
                                                          f"👑 | Доступ: {rank}\n"
                                                          f"🕹 | Всего наиграно: {hours} ч. {minutes} м.\n"
                                                          f"🕒 | Последняя сессия: {last_session_minutes} м.\n"
                                                          f"============\n"
                                                          f"🔐 | Последний вход:\n"
                                                          f"» Дата - {last_date}\n"
                                                          f"» IP - {ip}\n"
                                                          f"» OS - {os}\n"
                                                          f"» Устройство - {device}\n"
                                                          f"============\n"
                                                          f"📰 | Помощь - помощь")
                            else:
                                send_vk_reply_message(user_id, message_id, "🚫 | Ваш аккаунт не привязан или привязка не завершена.")
                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | Вы не выбрали аккаунт для просмотра профиля. Используйте /аккаунт выбрать.")
                    
                    elif args.startswith("принять"):
                        selected_account = get_selected_account(user_id)

                        if selected_account:
                            # Проверка наличия активного запроса
                            cursor.execute("SELECT username FROM vk_links WHERE vk_id = ? AND username = ? AND process = 'pending'", (user_id, selected_account))
                            row = cursor.fetchone()

                            if row:
                                cursor.execute("UPDATE vk_links SET process = 'approved' WHERE username = ?", (selected_account,))
                                conn.commit()
                                send_vk_reply_message(user_id, message_id, f"✅ | Вход успешно одобрен для аккаунта '{selected_account}'.")
                            else:
                                send_vk_reply_message(user_id, message_id, "🚫 | У вас нет активных запросов или вы не в игре.")
                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | Вы не выбрали аккаунт для принятия запроса. Используйте /аккаунт выбрать.")
                    
                    elif args.startswith("отклонить"):
                        selected_account = get_selected_account(user_id)

                        if selected_account:
                            # Проверяем наличие запроса
                            cursor.execute("SELECT username FROM vk_links WHERE vk_id = ? AND username = ? AND process = 'pending'", (user_id, selected_account))
                            row = cursor.fetchone()

                            if row:
                                cursor.execute("UPDATE vk_links SET process = 'denied' WHERE username = ?", (selected_account,))
                                conn.commit()
                                send_vk_reply_message(user_id, message_id, f"⛔ | Вход успешно отклонён для аккаунта '{selected_account}'.")
                            else:
                                send_vk_reply_message(user_id, message_id, "🚫 | У вас нет активных запросов или вы не в игре.")
                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | Вы не выбрали аккаунт для отклонения запроса. Используйте /аккаунт выбрать.")
                    
                    elif args.startswith("восстановить"):
                        selected_account = get_selected_account(user_id)

                        if selected_account:
                            try:
                                _, new_password = args.split(' ', 1)
                            except ValueError:
                                send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте /аккаунт восстановить [новый_пароль].")
                                continue

                            cursor.execute("SELECT vk_id, username FROM vk_links WHERE vk_id = ? AND username = ?", (user_id, selected_account))
                            row = cursor.fetchone()

                            if row:
                                vk_id, username = row
                                cursor.execute("SELECT last_reset_time FROM others WHERE vk_id = ?", (vk_id,))
                                last_reset_row = cursor.fetchone()

                                # Проверяем, если last_reset_time == NULL
                                if last_reset_row is None or last_reset_row[0] is None:
                                    # Если last_reset_time NULL, пропускаем проверку времени
                                    send_vk_reply_message(user_id, message_id, f"👤 | Смена пароля для аккаунта '{username}'\n\n🔑 | Ваш новый пароль: {new_password}\n\n✏️ | Для подтверждения - напишите в чат 'ПОДТВЕРЖДАЮ' заглавными буквами")
                                else:
                                    last_reset_time = datetime.strptime(last_reset_row[0], "%Y-%m-%d %H:%M:%S")
                                    current_time = datetime.now()
                                    time_diff = current_time - last_reset_time

                                    # Проверяем, если разница меньше часа
                                    if time_diff < timedelta(hours=1):
                                        remaining_time = timedelta(hours=1) - time_diff
                                        minutes, seconds = divmod(remaining_time.seconds, 60)
                                        hours, minutes = divmod(minutes, 60)
                                        send_vk_reply_message(user_id, message_id, f"🚫 | Вы уже использовали команду. Подождите {hours} ч. {minutes} м. для повторного использования.")
                                        continue  # Завершаем выполнение функции, если время ожидания не истекло

                                    # Если время прошло, продолжаем с сменой пароля
                                    send_vk_reply_message(user_id, message_id, f"👤 | Смена пароля для аккаунта '{username}'\n\n🔑 | Ваш новый пароль: {new_password}\n\n✏️ | Для подтверждения - напишите в чат 'ПОДТВЕРЖДАЮ' заглавными буквами")

                                confirmation_received = False
                                for confirmation_event in longpoll.listen():
                                    if confirmation_event.type == VkEventType.MESSAGE_NEW and confirmation_event.to_me and confirmation_event.user_id == user_id:
                                        if confirmation_event.text.strip() == 'ПОДТВЕРЖДАЮ':
                                            auth_conn = sqlite3.connect(auth_db_path)
                                            auth_cursor = auth_conn.cursor()

                                            try:
                                                auth_cursor.execute("UPDATE auth SET password = ? WHERE name = ?", (new_password, username))
                                                auth_conn.commit()
                                                send_vk_reply_message(user_id, message_id, f"✅ | Вы успешно сменили пароль для аккаунта '{username}'")

                                                current_time_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                                                cursor.execute("REPLACE INTO others (vk_id, last_reset_time) VALUES (?, ?)", (vk_id, current_time_str))
                                                cursor.execute("UPDATE others SET selected_account = ? WHERE vk_id = ?", (selected_account, vk_id))
                                                conn.commit()
                                            except sqlite3.Error:
                                                send_vk_reply_message(user_id, message_id, "🚫 | Ошибка при обновлении пароля. Попробуйте позже.")
                                            finally:
                                                auth_conn.close()
                                            confirmation_received = True
                                            break
                                        else:
                                            send_vk_reply_message(user_id, message_id, "⛔ | Вы отменили процесс смены пароля.")
                                            confirmation_received = True
                                            break
                                
                                if not confirmation_received:
                                    send_vk_reply_message(user_id, message_id, "🚫 | Процесс смены пароля был прерван.")
                            else:
                                send_vk_reply_message(user_id, message_id, "🚫 | Аккаунт не найден или не привязан.")
                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | Вы не выбрали аккаунт для восстановления пароля. Используйте /аккаунт выбрать.")
                    
                    elif args.startswith("разбан"):
                        selected_account = get_selected_account(user_id)

                        if selected_account:
                            # Проверяем, заблокирован ли аккаунт (поле process = 'denied')
                            cursor.execute("SELECT username FROM vk_links WHERE vk_id = ? AND username = ? AND process = 'denied'", (user_id, selected_account))
                            row = cursor.fetchone()

                            if row:
                                # Если аккаунт заблокирован, меняем статус на 'approved'
                                cursor.execute("UPDATE vk_links SET process = 'approved' WHERE username = ?", (selected_account,))
                                conn.commit()
                                send_vk_reply_message(user_id, message_id, f"✅ | Аккаунт '{selected_account}' успешно разблокирован!")
                            else:
                                # Если аккаунт не заблокирован
                                send_vk_reply_message(user_id, message_id, "🚫 | Аккаунт не заблокирован системой 2FA.")
                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | Вы не выбрали аккаунт для разблокировки. Используйте /аккаунт выбрать.")
                    
                    else:
                        send_vk_reply_message(user_id, message_id, "🚫 не выбраны аргументы. Введите /аккаунт [список, выбрать, профиль, принять, отклонить, восстановить, разбан]")
  
                if message_text.startswith('/vk-info'):
                    # Извлечение никнейма из команды
                    username = get_username_from_message(message_text)
                    if not username:
                        send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат.\n » /vk-info [ник].")
                        continue

                    # Проверяем, привязан ли аккаунт к VK ID
                    user_data = get_user_data(username)
                    if not user_data:
                        send_vk_reply_message(user_id, message_id, "🚫 | Никнейм не привязан ВК.\n (попробуй в нижнем регистре ник)")
                        continue

                    # Проверяем ранг пользователя по его vk_id
                    selected_account = get_selected_account(user_id)
                    cursor.execute("SELECT rank FROM vk_rcon WHERE nickname = ?", (selected_account,))
                    rank_row = cursor.fetchone()
    
                    if not rank_row:
                        send_vk_reply_message(user_id, message_id, "🚫 | У вас нет прав для использования этой команды.")
                        continue

                    # Проверяем, соответствует ли ранг одному из разрешенных
                    user_rank = rank_row[0]
                    allowed_ranks = ['Moderator', 'SuperAdmin', 'SeniorAdmin', 'Deputy', 'Developer', 'Administrator', 'Support']

                    if user_rank not in allowed_ranks:
                        send_vk_reply_message(user_id, message_id, "🚫 | У вас нет прав для использования этой команды.")
                        continue

                    # Получаем дополнительные данные по пользователю
                    vk_id = user_data['vk_id']
                    nickname = get_username_from_message(message_text)
                    rcon_data = get_rcon_data(nickname)
                    play_time = get_playtime(username)  # Предполагается, что у вас есть эта функция

                    # Формируем сообщение
                    player_info_message = format_player_info_message(username, vk_id, rcon_data, play_time)

                    # Отправляем сообщение
                    send_vk_reply_message(user_id, message_id, player_info_message)

                elif message_text.startswith('/настройки'):
                    args = message_text.split()

                    # Получаем выбранный аккаунт
                    selected_account = get_selected_account(user_id)

                    if selected_account is None:
                        send_vk_reply_message(user_id, message_id, "🚫 | Вы не выбрали аккаунт. Используйте /аккаунт выбрать.")
                        continue

                    # Обработка изменения настроек
                    if len(args) == 3:
                        option = args[1].lower()
                        value = args[2].lower()

                        if value == 'вкл':
                            new_value = 'YES'
                        elif value == 'выкл':
                            new_value = 'NO'
                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | Неверное значение. Используйте 'вкл' или 'выкл'.")
                            continue

                        if option == 'рассылка':
                            cursor.execute("UPDATE settings SET mailing = ? WHERE nickname = ?", (new_value, selected_account))
                            conn.commit()
                            send_vk_reply_message(user_id, message_id, f"⚙️ | Опция 'рассылка' для аккаунта {selected_account} успешно изменена.")

                        elif option == 'ip':
                            cursor.execute("UPDATE settings SET twofa_active = ? WHERE nickname = ?", (new_value, selected_account))
                            conn.commit()
                            send_vk_reply_message(user_id, message_id, f"⚙️ | Опция 'IP' для аккаунта {selected_account} успешно изменена.")

                        elif option == 'cid':
                            if value == 'вкл':
                                send_vk_reply_message(user_id, message_id, f"🚫 | Опция 'CID' не может быть включена в боте.\n💡 | что бы включить защиту по CIDу зайдите на сервер под ником  {selected_account} и напишите /2fa cid-on.")
                            elif value == 'выкл':
                                cursor_protect.execute("DELETE FROM cid WHERE player = ?", (selected_account,))
                                conn_protect.commit()
                                send_vk_reply_message(user_id, message_id, f"⚙️ | Опция 'CID' для аккаунта {selected_account} успешно отключена.")
                            else:
                                send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте '/настройки cid выкл'.")

                        elif option == 'skin':
                            if value == 'вкл':
                                send_vk_reply_message(user_id, message_id, f"🚫 | Опция 'SKIN' не может быть включена в боте.\n💡 | чтобы включить защиту по SKINу зайдите на сервер под ником {selected_account} и напишите /2fa skin-on.")
                            elif value == 'выкл':
                                cursor_protect.execute("DELETE FROM skin WHERE player = ?", (selected_account,))
                                conn_protect.commit()
                                send_vk_reply_message(user_id, message_id, f"⚙️ | Опция 'SKIN' для аккаунта {selected_account} успешно отключена.")
                            else:
                                send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте '/настройки skin выкл'.")

                        else:
                            send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте '/настройки [опция] [вкл/выкл]' или '/настройки'.")

                    # Вывод текущих настроек, если команда без аргументов
                    elif len(args) == 1:
                        cursor.execute("SELECT mailing, twofa_active FROM settings WHERE nickname = ?", (selected_account,))
                        settings = cursor.fetchone()

                        # Проверка состояния защиты по CID
                        cursor_protect.execute("SELECT hash FROM cid WHERE player = ?", (selected_account,))
                        cid_protection = cursor_protect.fetchone()

                        # Проверка состояния защиты по SKIN
                        cursor_protect.execute("SELECT hash FROM skin WHERE player = ?", (selected_account,))
                        skin_protection = cursor_protect.fetchone()

                        if settings is None:
                            # Если аккаунт не найден, создаем запись с дефолтными настройками
                            cursor.execute("INSERT INTO settings (nickname, vk_id, mailing, twofa_active) VALUES (?, ?, ?, ?)", (selected_account, user_id, 'YES', 'NO'))
                            conn.commit()
                            mailing_status = "✅"
                            fa_status = "⛔"
                            cid_status = "⛔"
                            skin_status = "⛔"
                            send_vk_reply_message(user_id, message_id, f"⚙️ | Для аккаунта {selected_account} были созданы настройки по умолчанию.\n\n📩 | Рассылка: {mailing_status}\n⛓️ | 2FA: {fa_status}\n🖥 | CID: {cid_status}\n👤 | SKIN: {skin_status}")
                        else:
                            mailing_status = "✅" if settings[0] == 'YES' else "⛔"
                            fa_status = "✅" if settings[1] == 'YES' else "⛔"
                            cid_status = "✅" if cid_protection else "⛔"
                            skin_status = "✅" if skin_protection else "⛔"
                            send_vk_reply_message(user_id, message_id, f"⚙️ | Настройки аккаунта {selected_account}\n📩 | Рассылка: {mailing_status}\n⛓️ | защита по IP: {fa_status}\n🖥 | защита по CID: {cid_status}\n👤 | защита по SKIN: {skin_status}")

                    else:
                        send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте '/настройки [опция] [вкл/выкл]' или '/настройки'.")
                        
                elif message_text.startswith('/юзер-лист'):
                    try:
                        # Проверка прав пользователя
                        selected_account = get_selected_account(user_id)
                        cursor.execute("SELECT rank FROM vk_rcon WHERE nickname = ?", (selected_account,))
                        user_rank = cursor.fetchone()

                        if not user_rank or user_rank[0] not in ['Support', 'Moderator', 'Manager', 'SuperAdmin', 'Administrator', 'SeniorAdmin']:
                            send_vk_reply_message(user_id, message_id, "🚫 | У вас недостаточно прав для использования этой команды.")
                            continue

                        # Получение номера страницы
                        _, page_str = message_text.split(' ', 1)
                        page = int(page_str) if page_str.isdigit() else 1
                        page_size = 3
                        offset = (page - 1) * page_size

                        # Получаем общее количество пользователей для расчета страниц
                        cursor.execute("SELECT COUNT(*) FROM vk_rcon")
                        total_users = cursor.fetchone()[0]
                        total_pages = (total_users + page_size - 1) // page_size  # Рассчитываем общее количество страниц

                        # Получаем список пользователей из базы
                        cursor.execute("SELECT nickname, rank FROM vk_rcon LIMIT ? OFFSET ?", (page_size, offset))
                        users = cursor.fetchall()

                        if users:
                            message = "📦 | Список всех аккаунтов в боте\n"
                            for user in users:
                                nickname, rank = user
                                message += f"👤 | Ник: {nickname}\n👑 | Доступ: {rank}\n"
                                message += "×××××××××××××××××\n"
                            message += f"Страница {page} из {total_pages}"
                            send_vk_reply_message(user_id, message_id, message)
                        else:
                            send_vk_reply_message(user_id, message_id, f"🚫 | Нет данных для страницы {page}.")
                    except ValueError:
                        send_vk_reply_message(user_id, message_id, "🚫 | Неверный формат. Используйте /юзер-лист [номер страницы].")
  #              else:
  #                  send_vk_reply_message(user_id, message_id, "🚫 | Команда не распознана. Используйте "помощь" для списка команд.")
  
  #==================================#
  #                                  #
  #   При фатальной ошибки пытается  #   # воскресить бота                  #
  #                                  #
  #==================================#
  
    except Exception as e:
        print(f"Ошибка в основном цикле обработки событий: {e}")
        traceback.print_exc()
        time.sleep(20)  # Задержка перед перезапуском, чтобы избежать слишком частых перезапусков

if __name__ == '__main__':
    attempt = 0
    max_attempts = 100

    while attempt < max_attempts:
        try:
            run_bot()
        except Exception as e:
            print(f"Ошибка при запуске бота: {e}")
            traceback.print_exc()
            attempt += 1
            time.sleep(15)  # Задержка перед повторной попыткой

    print("Бот завершил работу.")