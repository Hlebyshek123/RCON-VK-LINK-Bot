# ENCODING WITH UTF-8
#
#TODO
# возможно сделать /unreport-all,/pardon-all для support для разбана всех по нику

import vk_api
from vk_api.bot_longpoll import VkBotLongPoll, VkBotEventType
import random
import sqlite3
import mysql.connector
import yaml
import os
from datetime import datetime, timedelta
import hashlib
import threading
import mcrcon
import string
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

config_path = '/root/mysqlvk/config.yml'
with open(config_path, 'r') as file:
    config = yaml.safe_load(file)

access_token = config['access_token']
logs_admin_token = config['logs_admin_token']
rcon_ranks = config['permissions']
group_id = config['group_id']
mysql_host = config['mysql_host']
mysql_user = config['mysql_user']
mysql_pass = config['mysql_pass']
mysql_server_db = config['mysql_server_db']
mysql_bot_db = config['mysql_bot_db']

#===================================#
#                                   #
# Пути к базам данных и файлам      #
#                                   #
#===================================#

vk_id_path = '/root/linux/plugins/HlebBans_src/vk_id.db'

#===================================#
#                                   #
#    Подключение к базам данных     #
#                                   #
#===================================#

#(Это conn i cursor)
# Подключение к базе данных bot_data
connBot = mysql.connector.connect(
    host=mysql_host,
    user=mysql_user,
    password=mysql_pass,
    database=mysql_bot_db
)
connBot.autocommit = True
cursorBot = connBot.cursor()

# Подключение к базе vk_id (база банов)

conn_bans = sqlite3.connect(vk_id_path)
cursor_bans = conn_bans.cursor()

# Подключение к базе promocodes
promo_conn = mysql.connector.connect(
    host=mysql_host,
    user=mysql_user,
    password=mysql_pass,
    database=mysql_bot_db
)
promo_conn.autocommit = True
promo_cursor = promo_conn.cursor()

# Подключение к vkProtection

conn_protect = mysql.connector.connect(
    host=mysql_host,
    user=mysql_user,
    password=mysql_pass,
    database=mysql_server_db
)
conn_protect.autocommit = True
cursor_protect = promo_conn.cursor()

# Подключение к server_db

connSurvival = mysql.connector.connect(
            host=mysql_host,
            user=mysql_user,
            password=mysql_pass,
            database=mysql_server_db
        )

connSurvival.autocommit = True
cursorSurvival = connSurvival.cursor()

#===================================#
#                                   #
# Проверка и создание таблиц в базе #
#                                   #
#===================================#

cursorBot.execute('''
    CREATE TABLE IF NOT EXISTS vk_links (
        username VARCHAR(255) UNIQUE,
        vk_id BIGINT,
        vk_code VARCHAR(255),
        link VARCHAR(255) DEFAULT 'NO'
    )
''')

cursorBot.execute('''
    CREATE TABLE IF NOT EXISTS vk_rcon (
        nickname VARCHAR(255) UNIQUE,
        vk_id BIGINT,
        rank VARCHAR(50),
        banned VARCHAR(10) DEFAULT 'NO',
        ban_reason TEXT,
        ban_time DATETIME,
        selected_server VARCHAR(50)
    )
''')

cursorBot.execute('''
    CREATE TABLE IF NOT EXISTS others (
        vk_id BIGINT UNIQUE,
        last_reset_time DATETIME,
        selected_account VARCHAR(255)
    )
''')

cursorBot.execute('''
    CREATE TABLE IF NOT EXISTS settings (
        nickname VARCHAR(255),
        vk_id BIGINT NOT NULL,
        mailing VARCHAR(10) DEFAULT 'YES'
    )
''')

promo_cursor.execute('''
    CREATE TABLE IF NOT EXISTS promo (
        promo_name VARCHAR(255) PRIMARY KEY,
        promo_desc TEXT NOT NULL,
        promo_type VARCHAR(50) NOT NULL,
        promo_count INT NOT NULL,
        promo_rank VARCHAR(255),
        promo_group VARCHAR(255),
        promo_money INT DEFAULT 0
    )
''')

promo_cursor.execute('''
    CREATE TABLE IF NOT EXISTS used_promo (
        promo_name VARCHAR(255) NOT NULL,
        nickname VARCHAR(255) NOT NULL,
        PRIMARY KEY (promo_name, nickname),
        FOREIGN KEY (promo_name) REFERENCES promo(promo_name)
    )
''')

#===================================#
#                                   #
#      Авторизация ВКонтакте        #
#                                   #
#===================================#

vk_session = vk_api.VkApi(token=access_token)
vk = vk_session.get_api()

# Инициализация LongPoll для групп
longpoll = VkBotLongPoll(vk_session, group_id)

longpoll.ts = vk_session.method("groups.getLongPollServer", {"group_id": group_id})["ts"]

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

def send_vk_message(vk_session, user_id, message):
    vk = vk_session.get_api()
    vk.messages.send(
        user_id=user_id,
        message=message,
        random_id=random.randint(1, 1e6)
    )

#===================================#
#                                   #
#          Функции бота             #
#                                   #
#===================================#

def get_playtime(username):
    try:
        cursorSurvival.execute("SELECT time FROM playtime WHERE nickname = %s", (username,))
        row = cursorSurvival.fetchone()
        #connSurvival.close()

        if row:
            seconds = row[0]
            hours, remainder = divmod(seconds, 3600)
            minutes, _ = divmod(remainder, 60)
            return hours, minutes
    except mysql.connector.Error as e:
        print(f"Ошибка при получении времени игры для пользователя {username}: {e}")
    return 0, 0

def get_last_session(username):
    try:
        cursorSurvival.execute("SELECT session_time FROM playtime WHERE nickname = %s", (username,))
        row = cursorSurvival.fetchone()
        #connSurvival.close()

        if row:
            seconds = row[0]
            minutes, _ = divmod(seconds, 60)
            return minutes
    except mysql.connector.Error as e:
        print(f"Ошибка при получении последней сессии для пользователя {username}: {e}")
    return 0

def get_last_date(username):
    try:
        cursorSurvival.execute("SELECT last_date FROM users WHERE nickname = %s", (username,))
        row = cursorSurvival.fetchone()
        
        if row and row[0]:
            # Конвертация UNIX-времени в строку
            dt = datetime.fromtimestamp(row[0])
            return dt.strftime("%Y-%m-%d %H:%M:%S")
        else:
            return 'Неизвестно'
    except mysql.connector.Error as e:
        print(f"Ошибка при получении последней даты для пользователя {username}: {e}")
    return 'Неизвестно'

def generate_promo_code():
    return '-'.join([''.join(random.choices(string.ascii_uppercase, k=5)) for _ in range(3)])

def hash_password(new_password):
    
    new_password_bytes = new_password.encode('utf-8')
    
    sha512_hash = hashlib.sha512()
    
    sha512_hash.update(new_password_bytes)
    
    hashed_password = sha512_hash.hexdigest()
    
    return hashed_password
    
#===================================#
#                                   #
#     Основная RCON функция         #
#                                   #
#===================================#

def execute_rcon_command(user_id, vk_session, command, *args):
    try:
        selected_account = get_selected_account(user_id)
        selected_server = get_selected_server(selected_account)
        
        if not selected_account:
            send_vk_message(vk_session, user_id, "🚫 | У вас не выбран аккаунт для выполнения команды.")
            return

        if not selected_server:
            send_vk_message(vk_session, user_id, "🚫 | Вы не выбрали сервер для подключения. \n » /rcon выбрать [имя сервера].")
            return

        # Получаем данные выбранного сервера из конфига
        server_config = config['servers'].get(selected_server)
        if not server_config:
            send_vk_message(vk_session, user_id, "🚫 | Некорректный выбор сервера.")
            return

        # Соединяемся с сервером через RCON
        full_command = f"{command} " + " ".join(args)
        with mcrcon.MCRcon(server_config['rcon_host'], server_config['rcon_password'], port=server_config['rcon_port']) as mcr:
            response = mcr.command(full_command)

        if response.strip():  # Проверяем, что ответ не пустой или не состоит из одних пробелов
            send_vk_message(vk_session, user_id, f"✅ | Вы успешно отправили команду на сервер {selected_server} (by {selected_account}) :\n {response}")
        else:
            # Сообщение, если сервер вернул пустой ответ
            send_vk_message(vk_session, user_id, f"✅ | Вы успешно отправили команду на сервер {selected_server} (by {selected_account}) :\n📩 | сервер вернул пустой ответ")
    
    except Exception as e:
        send_vk_message(vk_session, user_id, f"🚫 | Ошибка при выполнении команды ({selected_server}): \n{str(e)}")

#===================================#
#                                   #
#    RCON функция для промокодов    #
#                                   #
#===================================#


def rcon_command(command, server_name):
    server_config = config['servers'].get(server_name)
    if not server_config:
        return None

    try:
        with mcrcon.MCRcon(server_config['rcon_host'], server_config['rcon_password'], port=server_config['rcon_port']) as mcr:
            response = mcr.command(command)
            return response
    except Exception as e:
        print(f"Ошибка RCON: {e}")
        return None

######

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

def get_bot_allowed_ranks(bot_cmd):
    """Получаем список рангов, которые могут использовать Команды бота."""
    return config['allowed_ranks'].get(bot_cmd, [])

def get_bot_valid_ranks(valid):
    """Получаем список рангов, которые есть в боте."""
    return config['valid_ranks'].get(valid, [])

def get_selected_server(selected_account):
    """Получаем выбранный сервер пользователя из базы данных."""
    try:
        cursorBot.execute("SELECT selected_server FROM vk_rcon WHERE nickname = %s", (selected_account,))
        row = cursorBot.fetchone()
        return row[0] if row else None
    except mysql.connector.Error as e:
        print(f"Ошибка при получении выбранного сервера: {e}")
        return None
    #finally:
        #cursorBot.close()
        #connBot.close()
        #pass

def select_server(server_name, user_id):
    """Сохраняем выбор сервера в базе данных."""
    try:
        selected_account = get_selected_account(user_id)
        cursorBot.execute("UPDATE vk_rcon SET selected_server = %s WHERE nickname = %s", (server_name, selected_account))
    except mysql.connector.Error as e:
        print(f"Ошибка при обновлении выбранного сервера: {e}")
    #finally:
        #connBot.close()
        #cursorBot.close()

def ban_player(username, ban_reason, ban_duration_hours, user_id):
    ban_time = datetime.now() + timedelta(hours=ban_duration_hours)
    ban_time_str = ban_time.strftime("%Y-%m-%d %H:%M:%S")

    # Обновляем информацию о бане в базе данных с причиной, где пробелы заменены на подчеркивания
    cursorBot.execute("UPDATE vk_rcon SET banned = 'YES', ban_reason = %s, ban_time = %s WHERE nickname = %s", (ban_reason, ban_time_str, username))
    #connBot.close()
    #cursorBot.close()
    # Получаем vk_id игрока и отправляем личное сообщение
    cursorBot.execute("SELECT vk_id FROM vk_rcon WHERE nickname = %s", (username,))
    row = cursorBot.fetchone()
    display_ban_reason = ban_reason.replace('_', ' ')
    if row:
        vk_id = row[0]
        # Замена подчеркиваний на пробелы при выводе
        #display_ban_reason = ban_reason.replace('_', ' ')
        send_vk_message(vk_session, vk_id, f"🚫 | [id{vk_id}|{username}], вы были заблокированы в консоли сервера по причине: {display_ban_reason}. Разбан через: {ban_duration_hours} час(ов).\n 💉 | Доки: @roltoncraft_logs")

    # Получаем никнейм администратора
    selected_account = get_selected_account(user_id)
    cursorBot.execute("SELECT nickname FROM vk_rcon WHERE nickname = %s", (selected_account,))
    admin_row = cursorBot.fetchone()
    #connBot.close()
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
    cursorBot.execute("UPDATE vk_rcon SET banned = 'NO', ban_reason = NULL, ban_time = NULL WHERE nickname = %s", (username,))
    #connBot.close()
    #cursorBot.close()

    # Получаем vk_id игрока и отправляем сообщение о разбане
    cursorBot.execute("SELECT vk_id FROM vk_rcon WHERE nickname = %s", (username,))
    row = cursorBot.fetchone()
    if row:
        vk_id = row[0]
        send_vk_message(vk_session, vk_id, "✅ | Вы были разбанены в консоли сервера.")

        # Проверяем, кто выполняет разбан: бот или администратор
        if user_id is None:
            admin_nick = "Сервер"
            user_id = "229239390"# Если разбан выполняется ботом, указываем "Сервером"
        else:
            # Получаем никнейм администратора, если разбанил администратор
            selected_account = get_selected_account(user_id)
            cursorBot.execute("SELECT nickname FROM vk_rcon WHERE nickname = %s", (selected_account,))
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
    cursorBot.execute("SELECT vk_id, link FROM vk_links WHERE username = %s", (username,))
    user_row = cursorBot.fetchone()
    #connBot.close()
    if user_row and user_row[1] == 'YES':  # Только если аккаунт привязан
        return {'vk_id': user_row[0], 'username': username}
    return None

# Функция для получения данных из vk_rcon
def get_rcon_data(nickname):
    cursorBot.execute("SELECT rank, banned, ban_reason, ban_time FROM vk_rcon WHERE nickname = %s", (nickname,))
    rcon_row = cursorBot.fetchone()
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
    cursorBot.execute("SELECT username FROM vk_links WHERE vk_id = %s", (vk_id,))
    accounts = cursorBot.fetchall()
    accounts_list = ', '.join([account[0] for account in accounts]) if accounts else "Нет привязанных аккаунтов"

    hours, minutes = play_time

    # Проверяем, является ли пользователь администратором из config.yml
    is_admin = "Да" if vk_id in config['admins'] else "Нет"

    # Формируем итоговое сообщение
    player_info_message = f"""📝 | Найдена инфа по нику: {username}\n\n🔰 | ВК ID: @id{vk_id}\n🔑 | Доступ: {rank}\n🕹 | Всего наиграно: {hours} ч. {minutes} м.\n⛓️ | Привязанные акки: {accounts_list}\n🚫 | Бан в консоли: \n» Забанен: {banned}\n» Причина: {ban_reason}\n» Длительность: до {ban_time} MSK\n👑 | Выш.админ: {is_admin}"""
    return player_info_message

def get_selected_account(vk_id):
    try:
        # Выполняем запрос к таблице others, чтобы получить поле selected_account
        cursorBot.execute("SELECT selected_account FROM others WHERE vk_id = %s", (vk_id,))
        row = cursorBot.fetchone()

        # Если данные найдены, возвращаем selected_account
        if row:
            return row[0]
        else:
            return None

    except mysql.connector.Error as e:
        print(f"Ошибка при работе с базой данных: {e}")
        return None

    finally:
        # Закрываем курсор и соединение с базой данных
        #cursorBot.close()
        #connBot.close()
        pass

#===================================#
#                                   #
#    Основные обработчики команд    #
#                                   #
#===================================#

def run_bot():
    try:
        bot_start_time = time.time()
        
        for event in longpoll.listen():
            if event.type == VkBotEventType.MESSAGE_NEW:
                message_date = event.obj.message['date']
                if message_date < bot_start_time:
                    continue
                user_id = event.obj.message['from_id']
                peer_id = event.obj.message['peer_id']
                message_text = event.obj.message['text'].strip()
                message_id = event.obj.message['conversation_message_id']

                if message_text.startswith('/привязать'):
                    if user_id in last_unlink_time:
                        elapsed_time = time.time() - last_unlink_time[user_id]
                        if elapsed_time < 1800:  # 30 минут
                            remaining_time = 30 - (elapsed_time // 60)
                            send_vk_message(vk_session, user_id, f"🚫 | Чтоб снова привязать аккаунт, нужно подождать {remaining_time} минут.")
                            continue

                    try:
                        _, data = message_text.split(' ', 1)
                        username, vk_code = data.split(' ')
                    except ValueError:
                        send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте: /привязать [ник] [код].")
                        continue

                    # Ограничение длины данных
                    if len(username) > 20 or len(vk_code) > 11:
                        send_vk_message(vk_session, user_id, "🚫 | Слишком длинные данные. Проверьте ник и код.")
                        continue

                    # Проверка наличия пользователя в базе
                    try:
                        cursorBot.execute("SELECT COUNT(*) FROM vk_links WHERE vk_id = %s", (user_id,))
                        account_count = cursorBot.fetchone()[0]
                    except mysql.connector.Error as e:
                        send_vk_message(vk_session, user_id, "🚫 | Ошибка базы данных. Попробуйте позже.")
                        print(f"Ошибка базы данных: {e}")
                        continue

                    max_accounts = 5 if user_id in config['admins'] else 3

                    if account_count >= max_accounts:
                        send_vk_message(vk_session, user_id, f"🚫 | Достигнут лимит привязанных аккаунтов ({max_accounts}).")
                        continue

                    # Проверка, привязан ли никнейм
                    cursorBot.execute("SELECT * FROM vk_links WHERE username = %s AND vk_id IS NOT NULL", (username,))
                    if cursorBot.fetchone():
                        send_vk_message(vk_session, user_id, f"🚫 | Аккаунт {username} уже привязан к другому профилю!")
                        continue

                    # Проверка правильности данных
                    cursorBot.execute("SELECT * FROM vk_links WHERE username = %s AND vk_code = %s", (username, vk_code))
                    if cursorBot.fetchone():
                        cursor_bans.execute("INSERT INTO users (username, vk_id) VALUES (?, ?)", (username, user_id))
                        conn_bans.close()

                        cursorBot.execute("UPDATE vk_links SET vk_id = %s, link = 'YES' WHERE username = %s AND vk_code = %s", (user_id, username, vk_code))
                        #connBot.close()
                        
                        send_vk_message(vk_session, user_id, f"✅ Вы успешно привязали аккаунт '{username}'!\n\n🔒 Теперь ваш аккаунт защищен.")
                    else:
                        send_vk_message(vk_session, user_id, "🚫 | Неверный ник или код. Проверьте данные.")

                elif message_text == '/отвязать':
                    # Получение выбранного аккаунта через функцию
                    selected_account = get_selected_account(user_id)

                    if selected_account:
                        cursorBot.execute("SELECT * FROM vk_links WHERE vk_id = %s AND username = %s", (user_id, selected_account))
                        if cursorBot.fetchone():
                            send_vk_message(vk_session, user_id, f"😨 | Чтобы отвязать аккаунт {selected_account}, вы должны подтвердить свое действие\n\n✏️ | Для этого напишите в чат 'ПОДТВЕРЖДАЮ' заглавными буквами\n\n❗️ Внимание, данное действие является необратимым! После удаления своего аккаунта, вы не сможете привязать новый аккаунт в течение 30 минут\n(p.s а также доступ к консоли)")
                            
                            # Ожидание подтверждения
                            confirmation_received = False
                            for confirmation_event in longpoll.listen():
                                if confirmation_event.type == VkBotEventType.MESSAGE_NEW and confirmation_event.obj.message['from_id'] == user_id:
                                    if confirmation_event.obj.message['text'].strip() == 'ПОДТВЕРЖДАЮ':
                                        cursorBot.execute("DELETE FROM vk_links WHERE vk_id = %s AND username = %s", (user_id, selected_account))
                                        # Удаление из vk_rcon
                                        cursorBot.execute("DELETE FROM vk_rcon WHERE vk_id = %s AND nickname = %s", (user_id, selected_account))
                                        #connBot.close()
                                        cursor_bans.execute("DELETE FROM users WHERE vk_id = ? AND username = ?", (user_id, selected_account))
                                        conn_bans.close()
                                        send_vk_message(vk_session, user_id, f"😨 | Аккаунт {selected_account} успешно отвязан от вк.")
                                        # Сохранение времени отвязки
                                        last_unlink_time[user_id] = time.time()
                                        confirmation_received = True
                                        break
                                    else:
                                        send_vk_message(vk_session, user_id, "✅ | Вы отменили процесс отвязки.")
                                        confirmation_received = True
                                        break
                        else:
                            send_vk_message(vk_session, user_id, f"🚫 | Аккаунт {selected_account} не найден.")
                    else:
                        send_vk_message(vk_session, user_id, "🚫 | Вы не выбрали аккаунт для отвязки. Используйте /акк 2 [номер акка].")

                elif message_text == 'помощь':
                    help_message = (
                        "📰 | Доступные команды:\n"
                        "❤ | /привязать [ник] [код] - Привязать аккаунт.\n"
                        "💔 | /отвязать - Отвязать аккаунт.\n"
                        "🎗 | /акк [аргументы] - управление привязанными аккаунтами.\n » 1 - все привязанные аккаунты.\n » 2 - выбор аккаунта для взаимодействия с ботом.\n » 3 - посмотреть профиль выбранного аккаунта.\n » 4 [новый_пароль](/акк 4 пасс) - восстановление пароля.\n"
                        "📩 | /rcon - отправить Rcon команду на сервер.\n» 1 - покажет все доступные сервера.\n» 2 - выбор нужного сервера.\n"
                        "⚙️ | /настройки - управление настройками аккаунта.\n"
                        "🎁 | /промо активация [промо] [сервер] - активация промокодов в боте.\n"
                        "📰 | помощь - список всех команд"
                    )
                    send_vk_message(vk_session, user_id, help_message)
                if message_text == 'помощь админ':
                    # Проверка ранга пользователя в таблице vk_rcon
                    selected_account = get_selected_account(user_id)
                    bot_cmd = "admin_help"
                    cursorBot.execute("SELECT rank FROM vk_rcon WHERE nickname = %s", (selected_account,))
                    row = cursorBot.fetchone()

                    if row:
                        user_rank = row[0]  # Извлекаем ранг из результата запроса

                        # Список разрешенных рангов
                        allowed_ranks = get_bot_allowed_ranks(bot_cmd)

                        if user_rank in allowed_ranks:
                            # Если ранг пользователя есть в списке разрешенных рангов, показываем команды
                            admin_help_message = (
                                "🖥 | Админские команды:\n"
                                "⛓️ | /report - заблокировать доступ к консоли и ограничить его донатерские возможности.\n"
                                "(от Administrator)\n"
                                "📦 |/юзер-лист [номер страницы] - список аккаунтов с доступом.\n"
                                "(от Support)\n"
                                "📤 | /рассылка - разослать всем привязанным аккаунтам сообщение о чем-либо.\n"
                                "(только выш.адм)\n"
                                "👑 | /gp - выдать/изменить текущий ранг пользователя.\n"
                                "(только выш.адм)\n"
                                "✨ | /промо создать|список|удалить - управление промокодами в боте.\n» создать [доступ/привилегия] [ранг/прива] [количество] [описание]\n"
                                "(только выш.адм)\n"
                                "💠 | /vk-info [ник] - информация об данном игроке в системе.\n"
                                "(от Helper)"
                            )
                            send_vk_message(vk_session, user_id, admin_help_message)
                        else:
                            # Если ранг не соответствует, выводим сообщение о недостатке прав
                            send_vk_message(vk_session, user_id, "🚫 | У вас недостаточно прав для просмотра админ команд.")
                    else:
                        # Если данных о пользователе нет в таблице vk_rcon
                        send_vk_message(vk_session, user_id, "🚫 | У вас нет прав для просмотра админ команд.")

                elif message_text.startswith('/gp'):
                    # Проверяем, является ли пользователь администратором
                    if user_id not in config['admins']:
                        send_vk_message(vk_session, user_id, "🚫 | У вас нет прав для использования этой команды.")
                        continue

                    try:
                        _, data = message_text.split(' ', 1)
                        username, rank = data.split(' ')
                    except ValueError:
                        send_vk_message(vk_session, user_id, "🚫 | Неверный формат.\n » /gp [ник в нижнем регистре] [ранг].")
                        continue

                    # Список допустимых рангов
                    valid = "valid"
                    valid_ranks = get_bot_valid_ranks(valid)

                    # Проверка, что указанный ранг допустим
                    if rank not in valid_ranks:
                        send_vk_message(vk_session, user_id, f"🚫 | Неверный ранг. Доступные ранги: {', '.join(valid_ranks)}.")
                        continue

                    # Проверяем, привязан ли ник к VK ID и завершена ли привязка
                    cursorBot.execute("SELECT vk_id FROM vk_links WHERE username = %s AND link = 'YES'", (username,))
                    vk_id_row = cursorBot.fetchone()
                    #selected_server = get_selected_server(selected_account)

                    if vk_id_row:
                        vk_id = vk_id_row[0]
                        cursorBot.execute("REPLACE INTO vk_rcon (nickname, vk_id, rank) VALUES (%s, %s, %s)", (username, vk_id, rank))
                        #connBot.close()
                        send_vk_message(vk_session, user_id, f"✅ | Ранг {rank} успешно установлен для [id{vk_id}|{username}].")
                    else:
                        send_vk_message(vk_session, user_id, "🚫 | Ник не привязан или привязка не завершена.")

                elif message_text.startswith('/rcon'):
                    try:
                        command_parts = message_text.split()
                        # Если это просто команда /rcon без подкоманд
                        if len(command_parts) == 1:
                            send_vk_message(vk_session, user_id, "Шкибиди доп доп доп ес ес шкибеде доп доп\nhttps://m.youtube.com/watch?v=bagAoB4o6Os&pp=ygUwZXZlcnlib2R5IHdhbnRzIHRvIHJ1bGUgdGhlIHdvcmxkIHNraWJpZGkgdG9pbGV0")
                            continue

                        # Подкоманда /rcon выбрать [имя сервера]
                        if command_parts[1] == "1":
                            if len(command_parts) < 3:
                                send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте: /rcon 1 [имя сервера].")
                                continue

                            server_name = command_parts[2]
                            selected_account = get_selected_account(user_id)
                            
                            if not selected_account:
                                send_vk_message(vk_session, user_id, "🚫 | У вас не выбран аккаунт для выполнения команды.")
                                continue

                            # Проверяем права пользователя на выбор сервера
                            cursorBot.execute("SELECT rank FROM vk_rcon WHERE nickname = %s", (selected_account,))
                            result = cursorBot.fetchone()
                            if result is not None:
                                user_rank = result[0]
                            else:
                                    user_rank = "Неизвестно"
                            
                            allowed_servers = get_server_permissions(user_rank)
                            
                            
                            if server_name not in allowed_servers:
                                send_vk_message(vk_session, user_id, f"🚫 | Сервер {server_name} недоступен для вашего ранга.")
                            else:
                                # Сохраняем выбранный сервер
                                select_server(server_name, user_id)
                                send_vk_message(vk_session, user_id, f"🖥 Вы успешно выбрали сервер {server_name} с аккаунта {selected_account}")

                        # Подкоманда /rcon сервера
                        elif command_parts[1] == "2":
                            selected_account = get_selected_account(user_id)
                            
                            if not selected_account:
                                send_vk_message(vk_session, user_id, "🚫 | У вас не выбран аккаунт для выполнения команды.")
                                continue

                            cursorBot.execute("SELECT rank FROM vk_rcon WHERE nickname = %s", (selected_account,))
                            result = cursorBot.fetchone()
                            if result is not None:
                                user_rank = result[0]
                            else:
                                user_rank = "Неизвестно"

                            allowed_servers = get_server_permissions(user_rank)

                            if allowed_servers:
                                message = "🖥 | Доступные сервера:\n"
                                for i, server in enumerate(allowed_servers, start=1):
                                    message += f"✨ {i}. {server}\n"
                                send_vk_message(vk_session, user_id, message)
                            else:
                                send_vk_message(vk_session, user_id, f"🚫 | {selected_account}, у вас нет доступа к серверам.")

                        # Если это RCON команда
                        else:
                            try:
                                _, command_with_args = message_text.split(' ', 1)  # Получаем команду и аргументы
                                command_parts = command_with_args.split()  # Разделяем команду и аргументы
                                command = command_parts[0]  # Основная команда
                                arguments = command_parts[1:]  # Остальные аргументы

                                # Проверка на выбранный аккаунт из базы данных
                                cursorBot.execute("SELECT selected_account FROM others WHERE vk_id = %s", (user_id,))
                                row = cursorBot.fetchone()
                                if row and row[0]:
                                    selected_account = row[0]  # Получаем значение selected_account из БД
                                else:
                                    send_vk_message(vk_session, user_id, "🚫 | Вы не выбрали аккаунт для выполнения ркон команды. Используйте /акк 2 [номер акка].")
                                    continue

                                cursorBot.execute("SELECT rank, banned, ban_reason, ban_time, nickname FROM vk_rcon WHERE vk_id = %s AND nickname = %s", (user_id, selected_account))
                                rcon_row = cursorBot.fetchone()

                                if rcon_row:
                                    rank, banned, ban_reason, ban_time, nickname = rcon_row

                                    # Проверяем, забанен ли пользователь
                                    if banned == 'YES':
                                        #remaining_time = datetime.strptime(ban_time, "%Y-%m-%d %H:%M:%S") - datetime.now()
                                        #remaining_time = datetime.strptime(str(ban_time), "%Y-%m-%d %H:%M:%S") - datetime.now()
                                        remaining_time = ban_time - datetime.now()
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
                                            
                                            send_vk_message(vk_session, user_id, f"🚫 | [id{user_id}|{selected_account}], вы были заблокированы в консоли сервера. по причине: {ban_reason}. разбан через: {remaining_time_formatted}.\n 💉 | Доки: @roltoncraft_logs")
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
                                                execute_rcon_command(user_id, vk_session, "hban", target_nickname, duration, ban_reason_with_account)
                                                #send_vk_message(user_id, message_id  f"⛓️ | Игрок {target_nickname} был забанен на {duration} минут по причине: {ban_reason_with_account}.")
                                            else:
                                                send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте: /rcon hban [ник] [время] [причина].")
                                        elif command == "say":
                                            # Создаем сообщение с префиксом и именем пользователя
                                            message = ' '.join(arguments)
                                            formatted_message = f"{message} (by {nickname})"
                                            execute_rcon_command(user_id, vk_session, "say", formatted_message)
                                        elif command == "hkick":
                                            if len(arguments) >= 2:
                                                target_nickname = arguments[0]
                                                kick_reason = ' '.join(arguments[1:])

                                                # Добавляем (by {selected_account}) в конец причины кика
                                                kick_reason_with_account = f"{kick_reason} (by {selected_account})"

                                                # Выполнение команды кика с обновленной причиной
                                                execute_rcon_command(user_id, vk_session, "hkick", target_nickname, kick_reason_with_account)
                                                #send_vk_message(user_id, message_id  f"⛓️ | Игрок {target_nickname} был кикнут с сервера по причине: {kick_reason_with_account}.")
                                            else:
                                                send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте: /rcon hkick [ник] [причина].")
                                        elif command == "hmute":
                                            if len(arguments) >= 3:
                                                target_nickname = arguments[0]
                                                duration = arguments[1]
                                                mute_reason = ' '.join(arguments[2:])

                                                # Добавляем (by {selected_account}) в конец причины мута
                                                mute_reason_with_account = f"{mute_reason} (by {selected_account})"

                                                # Выполнение команды мута с обновленной причиной
                                                execute_rcon_command(user_id, vk_session, "hmute", target_nickname, duration, mute_reason_with_account)
                                                #send_vk_message(user_id, message_id  f"🔇 | Игрок {target_nickname} был замучен на {duration} минут по причине: {mute_reason_with_account}.")
                                            else:
                                                send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте: /rcon hmute [ник] [время] [причина].")
                                        else:
                                            # Выполнение произвольной команды RCON
                                            execute_rcon_command(user_id, vk_session ,command, *arguments)
                                    else:
                                        send_vk_message(vk_session, user_id, "🚫 | У вас нет прав для выполнения этой команды.")
                                else:
                                    send_vk_message(vk_session, user_id, "🚫 | Ваш ркон ранг не установлен или вы не привязаны.")
                            except ValueError:
                                send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте /rcon [команда] [аргументы].")

                    except ValueError:
                        send_vk_message(vk_session, user_id, "🚫 | Неверный формат команды.")
                                    

                elif message_text.startswith('/рассылка'):
                    # Проверка на то, что пользователь является администратором
                    if user_id in config['admins']:
                        # Получение текста сообщения для рассылки
                        reply_message_text = event.obj.message['text'].split('/рассылка', 1)[-1].strip()

                        if reply_message_text:
                            # Получение пользователей с рассылкой mailing = YES
                            cursorBot.execute("""
                                SELECT DISTINCT vk_links.vk_id 
                                FROM vk_links
                                JOIN settings ON vk_links.username = settings.nickname
                                WHERE vk_links.vk_id IS NOT NULL AND settings.mailing = 'YES'
                            """)
                            users = cursorBot.fetchall()

                            if users:
                                # Отправка сообщения каждому уникальному пользователю
                                for user in users:
                                    vk_id = user[0]
                                    send_vk_message(vk_session, vk_id, reply_message_text)

                                send_vk_message(vk_session, user_id, "✅ | Сообщение успешно отправлено всем пользователям с активированной рассылкой.")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Нет пользователей с активированной рассылкой.")
                        else:
                            send_vk_message(vk_session, user_id, "🚫 | Вы не указали сообщение для рассылки.")
                    else:
                        send_vk_message(vk_session, user_id, "🚫 | У вас нет прав для выполнения команды.")

                elif message_text.startswith('/report'):
                    try:
                        _, data = message_text.split(' ', 1)

                        # Получаем выбранный аккаунт пользователя
                        selected_account = get_selected_account(user_id)

                        # Проверка забанен ли пользователь
                        cursorBot.execute("SELECT banned FROM vk_rcon WHERE nickname = %s", (selected_account,))
                        banned_status = cursorBot.fetchone()

                        if banned_status and banned_status[0] == 'YES':
                            send_vk_message(vk_session, user_id, f"🚫 | {selected_account}, вы не можете использовать эту команду, так как заблокированы в консоли.")
                            continue

                        # Проверка прав пользователя
                        cursorBot.execute("SELECT rank FROM vk_rcon WHERE nickname = %s", (selected_account,))
                        user_rank = cursorBot.fetchone()
                        bot_cmd = "report"
                        allowed_ranks = get_bot_allowed_ranks(bot_cmd)

                        if not user_rank or user_rank[0] not in allowed_ranks:
                            send_vk_message(vk_session, user_id, "🚫 | У вас недостаточно прав для использования этой команды.")
                            continue

                        if data.startswith('-'):
                            # Разбан игрока
                            username = data[1:]  # Убираем минус перед ником

                            # Проверка никнейма на нижний регистр
                            if not username.islower():
                                send_vk_message(vk_session, user_id, "🚫 | Никнейм игрока должен быть в нижнем регистре.")
                                continue

                            cursorBot.execute("SELECT banned FROM vk_rcon WHERE nickname = %s AND rank IS NOT NULL", (username,))
                            row = cursorBot.fetchone()

                            if row:
                                banned_status = row[0]

                                # Проверка находится ли игрок в бане
                                if banned_status == "NO":
                                    send_vk_message(vk_session, user_id, "🚫 | Игрок не находится в бане.")
                                    continue

                                unban_player(username, user_id)
                                send_vk_message(vk_session, user_id, f"✅ | Игрок {username} был разбанен в консоли сервера.")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Игрок не привязан к консоли.")
                        else:
                            # Бан игрока
                            components = data.split(' ')
                            username = components[0]
                            ban_reason = '_'.join(components[1:-1])  # Объединяем все части, кроме никнейма и времени, через "_"
                            ban_duration_str = components[-1]
                            ban_duration_hours = int(ban_duration_str)

                            # Проверка никнейма на нижний регистр
                            if not username.islower():
                                send_vk_message(vk_session, user_id, "🚫 | Никнейм игрока должен быть в нижнем регистре.")
                                continue

                            cursorBot.execute("SELECT rank FROM vk_rcon WHERE nickname = %s AND rank IS NOT NULL", (username,))
                            row = cursorBot.fetchone()

                            if row:
                                ban_player(username, ban_reason, ban_duration_hours, user_id)
                                send_vk_message(vk_session, user_id, f"🚫 | Игрок {username} был заблокирован в консоли сервера.")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Игрок не привязан к консоли.")

                    except ValueError:
                        send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте /report [ник игрока] [причина] [время в часах].\nили /report [-ник_игрока] для разбана.")

                if message_text.startswith('/акк'):
                    try:
                        _, args = message_text.split(' ', 1)
                    except ValueError:
                        send_vk_message(vk_session, user_id, "🚫 не выбраны аргументы. Введите /акк  [ 1 - список, 2 - выбрать, 3 - профиль, 4 - восстановление пароля]")
                        continue

                    if args == "1":
                        # Получаем все привязанные аккаунты пользователя
                        cursorBot.execute("SELECT username FROM vk_links WHERE vk_id = %s", (user_id,))
                        accounts = cursorBot.fetchall()

                        # Получаем выбранный аккаунт из таблицы others
                        selected_account = get_selected_account(user_id)

                        if accounts:
                            account_list = "\n".join([
                                f"✨ {i + 1}. {account[0]}{'✅' if account[0] == selected_account else ''}" 
                                for i, account in enumerate(accounts)
                            ])
                            send_vk_message(vk_session, user_id, f"🕹 | Ваши привязанные аккаунты:\n{account_list}\n📰 | помощь - помощь")
                        else:
                            send_vk_message(vk_session, user_id, "🚫 | У вас нет привязанных аккаунтов.")
                    
                    elif args.startswith("2"):
                        try:
                            _, account_number = args.split(' ', 1)
                            account_number = int(account_number) - 1  # Преобразуем номер аккаунта в индекс
                        except ValueError:
                            send_vk_message(vk_session, user_id, "🚫 | для выбора аккаунта используйте - /акк 2 [номер аккаунта]")
                            continue
                        
                        # Получаем список всех аккаунтов
                        cursorBot.execute("SELECT username FROM vk_links WHERE vk_id = %s", (user_id,))
                        accounts = cursorBot.fetchall()

                        if 0 <= account_number < len(accounts):
                            selected_account = accounts[account_number][0]

                            # Проверяем, существует ли запись с данным vk_id в таблице others
                            cursorBot.execute("SELECT 1 FROM others WHERE vk_id = %s", (user_id,))
                            row = cursorBot.fetchone()

                            if row:
                                # Если запись существует, обновляем поле selected_account
                                cursorBot.execute("UPDATE others SET selected_account = %s WHERE vk_id = %s", (selected_account, user_id))
                            else:
                                # Если записи нет, вставляем новую запись
                                cursorBot.execute("INSERT INTO others (vk_id, selected_account) VALUES (%s, %s)", (user_id, selected_account))

                            connBot.commit()
                            send_vk_message(vk_session, user_id, f"✅ | Аккаунт '{selected_account}' успешно выбран для дальнейших действий!")
                        else:
                            send_vk_message(vk_session, user_id, "🚫 | Некорректный номер аккаунта.")
                    
                    elif args == "3":
                        selected_account = get_selected_account(user_id)
                        
                        if selected_account:
                            # Отправляем информацию о выбранном аккаунте
                            cursorBot.execute("SELECT username, vk_id FROM vk_links WHERE vk_id = %s AND username = %s", (user_id, selected_account))
                            row = cursorBot.fetchone()

                            if row:
                                username, vk_id = row
                                hours, minutes = get_playtime(username)
                                last_session_minutes = get_last_session(username)
                                last_date = get_last_date(username)
                                
                                cursorSurvival.execute("SELECT last_ip, last_device, last_port FROM users WHERE nickname = %s", (username,))
                                auth_row = cursorSurvival.fetchone()
                                #connSurvival.close()

                                if auth_row:
                                    ip, device, port = auth_row
                                else:
                                    ip, device, port = "Неизвестно", "Неизвестно", "Неизвестно"

                                cursorBot.execute("SELECT rank FROM vk_rcon WHERE nickname = %s", (selected_account,))
                                rcon_row = cursorBot.fetchone()
                                rank = rcon_row[0] if rcon_row else "Нету"

                                send_vk_message(vk_session, user_id, f"📜 | Информация по аккаунту {username}: \n"
                                                          f"🔰 | ВК ID: {vk_id}\n"
                                                          f"👑 | Доступ: {rank}\n"
                                                          f"🕹 | Всего наиграно: {hours} ч. {minutes} м.\n"
                                                          f"🕒 | Последняя сессия: {last_session_minutes} м.\n"
                                                          f"============\n"
                                                          f"🔐 | Последний вход:\n"
                                                          f"» Дата - {last_date} MSK\n"
                                                          f"» IP - {ip}\n"
                                    
                                                          f"» Устройство - {device}\n"
                                                          f"» Порт - {port}\n"
                                                          f"============\n"
                                                          f"📰 | Помощь - помощь")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Ваш аккаунт не привязан или привязка не завершена.")
                        else:
                            send_vk_message(vk_session, user_id, "🚫 | Вы не выбрали аккаунт для просмотра профиля. Используйте /акк 2 [ номер аккаунта ].")
                    
                    elif args.startswith("4"):
                        selected_account = get_selected_account(user_id)

                        if selected_account:
                            try:
                                _, new_password = args.split(' ', 1)
                            except ValueError:
                                send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте /акк восстановить [новый_пароль].")
                                continue

                            cursorBot.execute("SELECT vk_id, username FROM vk_links WHERE vk_id = %s AND username = %s", (user_id, selected_account))
                            row = cursorBot.fetchone()

                            if row:
                                vk_id, username = row
                                cursorBot.execute("SELECT last_reset_time FROM others WHERE vk_id = %s", (vk_id,))
                                last_reset_row = cursorBot.fetchone()

                                # Проверяем, если last_reset_time == NULL
                                if last_reset_row is None or last_reset_row[0] is None:
                                    # Если last_reset_time NULL, пропускаем проверку времени
                                    send_vk_message(vk_session, user_id, f"👤 | Смена пароля для аккаунта '{username}'\n\n🔑 | Ваш новый пароль: {new_password}\n\n✏️ | Для подтверждения - напишите в чат 'ПОДТВЕРЖДАЮ' заглавными буквами")
                                else:
                                    last_reset_time = last_reset_row[0]
                                    current_time = datetime.now()
                                    time_diff = current_time - last_reset_time

                                    # Проверяем, если разница меньше часа
                                    if time_diff < timedelta(hours=1):
                                        remaining_time = timedelta(hours=1) - time_diff
                                        minutes, seconds = divmod(remaining_time.seconds, 60)
                                        hours, minutes = divmod(minutes, 60)
                                        send_vk_message(vk_session, user_id, f"🚫 | Вы уже использовали команду. Подождите {hours} ч. {minutes} м. для повторного использования.")
                                        continue  # Завершаем выполнение функции, если время ожидания не истекло

                                    # Если время прошло, продолжаем с сменой пароля
                                    send_vk_message(vk_session, user_id, f"👤 | Смена пароля для аккаунта '{username}'\n\n🔑 | Ваш новый пароль: {new_password}\n\n✏️ | Для подтверждения - напишите в чат 'ПОДТВЕРЖДАЮ' заглавными буквами")

                                confirmation_received = False
                                for confirmation_event in longpoll.listen():
                                    if confirmation_event.type == VkBotEventType.MESSAGE_NEW and confirmation_event.obj.message['from_id'] == user_id:
                                        if confirmation_event.obj.message['text'].strip() == 'ПОДТВЕРЖДАЮ':
                                            #auth_conn = sqlite3.connect(auth_db_path) # подключение к auth.db
                                            #auth_cursor = auth_conn_bot.cursor()
                                            hashed_password = hash_password(new_password)

                                            try:
                                                cursorSurvival.execute("UPDATE users SET password = %s WHERE nickname = %s", (hashed_password, username))
                                                connSurvival.commit()
                                                send_vk_message(vk_session, user_id, f"✅ | Вы успешно сменили пароль для аккаунта '{username}'")

                                                current_time_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                                                cursorBot.execute("REPLACE INTO others (vk_id, last_reset_time) VALUES (%s, %s)", (vk_id, current_time_str))
                                                cursorBot.execute("UPDATE others SET selected_account = %s WHERE vk_id = %s", (selected_account, vk_id))
                                                #connBot.close()
                                            except mysql.connector.Error:
                                                send_vk_message(vk_session, user_id, "🚫 | Ошибка при обновлении пароля. Попробуйте позже.")
                                            finally:
                                                connSurvival.close()
                                            confirmation_received = True
                                            break
                                        else:
                                            send_vk_message(vk_session, user_id, "⛔ | Вы отменили процесс смены пароля.")
                                            confirmation_received = True
                                            break
                                
                                if not confirmation_received:
                                    send_vk_message(vk_session, user_id, "🚫 | Процесс смены пароля был прерван.")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Аккаунт не найден или не привязан.")
                        else:
                            send_vk_message(vk_session, user_id, "🚫 | Вы не выбрали аккаунт для восстановления пароля. Используйте /акк 2 [номер акка].")
                    
                    else:
                        send_vk_message(vk_session, user_id, "🚫 не выбраны аргументы. Введите /акк [ 1 - список, 2 - выбрать, 3 - профиль, 4 - восстановление пароля]")
  
                if message_text.startswith('/vk-info'):
                    # Извлечение никнейма из команды
                    username = get_username_from_message(message_text)
                    if not username:
                        send_vk_message(vk_session, user_id, "🚫 | Неверный формат.\n » /vk-info [ник].")
                        continue

                    # Проверяем, привязан ли аккаунт к VK ID
                    user_data = get_user_data(username)
                    if not user_data:
                        send_vk_message(vk_session, user_id, "🚫 | Никнейм не привязан ВК.\n (попробуй в нижнем регистре ник)")
                        continue

                    # Проверяем ранг пользователя по его vk_id
                    selected_account = get_selected_account(user_id)
                    bot_cmd = "vk_info"
                    cursorBot.execute("SELECT rank FROM vk_rcon WHERE nickname = %s", (selected_account,))
                    rank_row = cursorBot.fetchone()
    
                    if not rank_row:
                        send_vk_message(vk_session, user_id, "🚫 | У вас нет прав для использования этой команды.")
                        continue

                    # Проверяем, соответствует ли ранг одному из разрешенных
                    user_rank = rank_row[0]
                    allowed_ranks = get_bot_allowed_ranks(bot_cmd)

                    if user_rank not in allowed_ranks:
                        send_vk_message(vk_session, user_id, "🚫 | У вас нет прав для использования этой команды.")
                        continue

                    # Получаем дополнительные данные по пользователю
                    vk_id = user_data['vk_id']
                    nickname = get_username_from_message(message_text)
                    rcon_data = get_rcon_data(nickname)
                    play_time = get_playtime(username)

                    # Формируем сообщение
                    player_info_message = format_player_info_message(username, vk_id, rcon_data, play_time)

                    # Отправляем сообщение
                    send_vk_message(vk_session, user_id, player_info_message)

                elif message_text.startswith('/настройки'):
                    args = message_text.split()

                    # Получаем выбранный аккаунт
                    selected_account = get_selected_account(user_id)

                    if selected_account is None:
                        send_vk_message(vk_session, user_id, "🚫 | Вы не выбрали аккаунт. Используйте /акк 2 [номер].")
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
                            send_vk_message(vk_session, user_id, "🚫 | Неверное значение. Используйте 'вкл' или 'выкл'.")
                            continue

                        if option == 'рассылка':
                            cursorBot.execute("UPDATE settings SET mailing = %s WHERE nickname = %s", (new_value, selected_account))
                            #connBot.close()
                            send_vk_message(vk_session, user_id, f"⚙️ | Опция 'рассылка' для аккаунта {selected_account} успешно изменена.")

                        elif option == 'вход-Hsng_fbfы':
                            cursorBot.execute("UPDATE settings SET join_notifications = %s WHERE nickname = %s", (new_value, selected_account))
                            #connBot.close()
                            send_vk_message(vk_session, user_id, f"⚙️ | Опция 'Входы' для аккаунта {selected_account} успешно изменена.")

                        elif option == 'cid':
                            if value == 'вкл':
                                send_vk_message(vk_session, user_id, f"🚫 | Опция 'CID' не может быть включена в боте.\n💡 | что бы включить защиту по CIDу зайдите на сервер под ником  {selected_account} и напишите /2fa cid-on.")
                            elif value == 'выкл':
                                cursor_protect.execute("DELETE FROM cid WHERE player = %s", (selected_account,))
                                conn_protect.close()
                                send_vk_message(vk_session, user_id, f"⚙️ | Опция 'CID' для аккаунта {selected_account} успешно отключена.")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте '/настройки cid выкл'.")

                        elif option == 'skin':
                            if value == 'вкл':
                                send_vk_message(vk_session, user_id, f"🚫 | Опция 'SKIN' не может быть включена в боте.\n💡 | чтобы включить защиту по SKINу зайдите на сервер под ником {selected_account} и напишите /2fa skin-on.")
                            elif value == 'выкл':
                                cursor_protect.execute("DELETE FROM skin WHERE player = %s", (selected_account,))
                                conn_protect.commit()
                                send_vk_message(vk_session, user_id, f"⚙️ | Опция 'SKIN' для аккаунта {selected_account} успешно отключена.")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте '/настройки skin выкл'.")

                        else:
                            send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте '/настройки [опция] [вкл/выкл]' или '/настройки'.")

                    # Вывод текущих настроек, если команда без аргументов
                    elif len(args) == 1:
                        cursorBot.execute("SELECT mailing FROM settings WHERE nickname = %s", (selected_account,))
                        settings = cursorBot.fetchone()

                        # Проверка состояния защиты по CID
                        cursor_protect.execute("SELECT hash FROM cid WHERE player = %s", (selected_account,))
                        cid_protection = cursor_protect.fetchone()

                        # Проверка состояния защиты по SKIN
                        cursor_protect.execute("SELECT hash FROM skin WHERE player = %s", (selected_account,))
                        skin_protection = cursor_protect.fetchone()

                        if settings is None:
                            # Если аккаунт не найден, создаем запись с дефолтными настройками
                            cursorBot.execute("INSERT INTO settings (nickname, vk_id, mailing) VALUES (%s, %s, %s)", (selected_account, user_id, 'YES', 'NO'))
                            connBot.commit()
                            mailing_status = "✅"
                            #join_status = "⛔"
                            cid_status = "⛔"
                            skin_status = "⛔"
                            send_vk_message(vk_session, user_id, f"⚙️ | Для аккаунта {selected_account} были созданы настройки по умолчанию.\n\n📩 | Рассылка: {mailing_status}\n🖥 | CID: {cid_status}\n👤 | SKIN: {skin_status}")
                        else:
                            mailing_status = "✅" if settings[0] == 'YES' else "⛔"
                            #join_status = "✅" if settings[1] == 'YES' else "⛔"
                            cid_status = "✅" if cid_protection else "⛔"
                            skin_status = "✅" if skin_protection else "⛔"
                            send_vk_message(vk_session, user_id, f"⚙️ | Настройки аккаунта {selected_account}\n📩 | Рассылка: {mailing_status}\n🖥 | защита по CID: {cid_status}\n👤 | защита по SKIN: {skin_status}")

                    else:
                        send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте '/настройки [опция] [вкл/выкл]' или '/настройки'.")
                        
                elif message_text.startswith('/юзер-лист'):
                    try:
                        # Проверка прав пользователя
                        selected_account = get_selected_account(user_id)
                        cursorBot.execute("SELECT rank FROM vk_rcon WHERE nickname = %s", (selected_account,))
                        user_rank = cursorBot.fetchone()
                        bot_cmd = "user_list"
                        allowed_ranks = get_bot_allowed_ranks(bot_cmd)

                        if not user_rank or user_rank[0] not in allowed_ranks:
                            send_vk_message(vk_session, user_id, "🚫 | У вас недостаточно прав для использования этой команды.")
                            continue

                        # Получение номера страницы
                        _, page_str = message_text.split(' ', 1)
                        page = int(page_str) if page_str.isdigit() else 1
                        page_size = 3
                        offset = (page - 1) * page_size

                        # Получаем общее количество пользователей для расчета страниц
                        cursorBot.execute("SELECT COUNT(*) FROM vk_rcon")
                        total_users = cursorBot.fetchone()[0]
                        total_pages = (total_users + page_size - 1) // page_size  # Рассчитываем общее количество страниц

                        # Получаем список пользователей из базы
                        cursorBot.execute("SELECT nickname, rank FROM vk_rcon LIMIT %s OFFSET %s", (page_size, offset))
                        users = cursorBot.fetchall()

                        if users:
                            message = "📦 | Список всех аккаунтов в боте\n"
                            for user in users:
                                nickname, rank = user
                                message += f"👤 | Ник: {nickname}\n👑 | Доступ: {rank}\n"
                                message += "------------------------------------\n"
                            message += f"Страница {page} из {total_pages}"
                            send_vk_message(vk_session, user_id, message)
                        else:
                            send_vk_message(vk_session, user_id, f"🚫 | Нет данных для страницы {page}.")
                    except ValueError:
                        send_vk_message(vk_session, user_id, "🚫 | Неверный формат. Используйте /юзер-лист [номер страницы].")

                if message_text.startswith('/промо'):
                    args = message_text.split()
                    selected_1account = get_selected_account(user_id)

                    # Проверка прав администратора
                    is_admin = user_id in config['admins']

                    # /промо список — доступ только для администраторов
                    if len(args) >= 2 and args[1] == 'список':
                        if not is_admin:
                            send_vk_message(vk_session, user_id, "🚫 | У вас нет прав для просмотра списка промокодов.")
                            continue

                        page = int(args[2]) if len(args) > 2 else 1
                        offset = (page - 1) * 2
                        promo_cursor.execute("SELECT promo_name, promo_desc, promo_type, promo_count FROM promo LIMIT 2 OFFSET %s", (offset,))
                        promos = promo_cursor.fetchall()

                        if not promos:
                            send_vk_message(vk_session, user_id, "📦 | Нет доступных промокодов на данной странице.")
                        else:
                            message = "📦 | Все промо\n\n"
                            for promo in promos:
                                promo_name, promo_desc, promo_type, promo_count = promo
                                promo_desc = promo_desc.replace('_', ' ')
                                message += f"🌟 | {promo_name}\n📝 | Описание: {promo_desc}\n🎁 | Категория: {promo_type}\n⚙️ | Количество: {promo_count}\n\n"
                            send_vk_message(vk_session, user_id, message)
                        continue

                    # /промо создать — доступ только для администраторов
                    elif len(args) >= 6 and args[1] == 'создать':
                        if not is_admin:
                            send_vk_message(vk_session, user_id, "🚫 | У вас нет прав для создания промокодов.")
                            continue

                        # Проверка, что введены все параметры
                        if len(args) < 6:
                            send_vk_message(vk_session, user_id, "🚫 | Неверный формат команды. Используйте: /промо создать [доступ/привилегия] [ранг/привилегия] [количество использований] [описание]")
                            continue

                        # Извлекаем параметры из команды
                        promo_type = args[2]
                        rank_priv = args[3]
                        promo_count = args[4]
                        promo_desc = " ".join(args[5:])  # Составляем описание, учитывая пробелы

                        # Проверка на то, что количество использований является числом
                        if not promo_count.isdigit():
                            send_vk_message(vk_session, user_id, "🚫 | Количество использований должно быть числом.")
                            continue
                        promo_count = int(promo_count)

                        # Генерация уникального промокода
                        promo_name = generate_promo_code()

                        # Заменяем пробелы в описании на подчеркивания для сохранения в БД
                        promo_desc = promo_desc.replace(' ', '_')

                        # Создание промокода в базе данных
                        if promo_type == 'доступ':
                            promo_cursor.execute("INSERT INTO promo (promo_name, promo_desc, promo_type, promo_rank, promo_count) VALUES (%s, %s, %s, %s, %s)",
                                                 (promo_name, promo_desc, promo_type, rank_priv, promo_count))
                        elif promo_type == 'привилегия':
                            promo_cursor.execute("INSERT INTO promo (promo_name, promo_desc, promo_type, promo_group, promo_count) VALUES (%s, %s, %s, %s, %s)",
                                                 (promo_name, promo_desc, promo_type, rank_priv, promo_count))
 
                        elif promo_type == 'деньги':
                            promo_cursor.execute("INSERT INTO promo (promo_name, promo_desc, promo_type, promo_money, promo_count) VALUES (%s, %s, %s, %s, %s)",
                                                 (promo_name, promo_desc, promo_type, rank_priv, promo_count))
                        else:
                            send_vk_message(vk_session, user_id, "🚫 | Неверная категория промокода. Доступны только 'доступ' и 'привилегия' и 'деньги' .")
                            continue

                        promo_conn.commit()
                        send_vk_message(vk_session, user_id, f"✅ Промокод '{promo_name}' успешно создан!")
                        continue

                    # /промо активация
                    elif len(args) >= 3 and args[1] == 'активация':
                        promo_code = args[2]
                        server_name = args[3] if len(args) > 3 else None

                        promo_cursor.execute("SELECT promo_desc, promo_type, promo_count, promo_rank, promo_group, promo_money FROM promo WHERE promo_name = %s", (promo_code,))
                        promo = promo_cursor.fetchone()

                        if not promo:
                            send_vk_message(vk_session, user_id, "🚫 | Промокод не найден или введен неверно.")
                            continue

                        promo_desc, promo_type, promo_count, promo_rank, promo_group, promo_money = promo
                        selected_account = get_selected_account(user_id)

                        if not selected_account:
                            send_vk_message(vk_session, user_id, "🚫 | Привязанный аккаунт не найден.")
                            continue

                        # Проверка на использование промокода ранее
                        promo_cursor.execute("SELECT * FROM used_promo WHERE promo_name = %s AND nickname = %s", (promo_code, selected_account))
                        if promo_cursor.fetchone():
                            send_vk_message(vk_session, user_id, "🚫 | Вы уже активировали этот промокод.")
                            continue

                        # Проверка оставшихся активаций
                        if promo_count <= 0:
                            send_vk_message(vk_session, user_id, "🚫 | Этот промокод больше не может быть использован.")
                            continue

                        # Активация промокода после подтверждения
                        if promo_type == 'доступ':
                            cursorBot.execute("SELECT rank FROM vk_rcon WHERE nickname = %s", (selected_account,))
                            user_rank = cursorBot.fetchone()[0]
                            if user_rank < promo_rank:
                                cursorBot.execute("UPDATE vk_rcon SET rank = %s WHERE nickname = %s", (promo_rank, selected_account))
                                connBot.commit()
                                send_vk_message(vk_session, user_id, f"📦 | Промокод активирован на акке {selected_account}!\n🌟 | Описание промо: {promo_desc.replace('_', ' ')}\n👑 | Доступ получен: {promo_rank}")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Вы не можете использовать этот промокод. Ваш ранг выше выдаваемого.")

                        elif promo_type == 'привилегия':
                            if not server_name:
                                send_vk_message(vk_session, user_id, "🚫 | Укажите сервер для активации привилегии в конце.")
                                continue

                            if server_name not in config['servers']:
                                send_vk_message(vk_session, user_id, f"🚫 | Сервер '{server_name}' не найден.")
                                continue

                            rcon_response = rcon_command(f"setgroup {selected_account} {promo_group}", server_name)
                            if rcon_response:
                                send_vk_message(vk_session, user_id, f"📦 | Промокод активирован на акке {selected_account}!\n🌟 | Описание промо: {promo_desc.replace('_', ' ')}\n{rcon_response.replace('[PurePerms] ', '👑 | ')}")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Ошибка при выдаче привилегии на сервере.\n{rcon_response.replace('[PurePerms ]', '👑 | ')}")

                        elif promo_type == 'деньги':
                            if not server_name:
                                send_vk_message(vk_session, user_id, "🚫 | Укажите сервер для активации денег в конце.")
                                continue

                            if server_name not in config['servers']:
                                send_vk_message(vk_session, user_id, f"🚫 | Сервер '{server_name}' не найден.")
                                continue

                            rcon_response = rcon_command(f"givemoney {selected_account} {promo_money}", server_name)

                            if rcon_response:
                                send_vk_message(vk_session, user_id, f"📦 | Промокод активирован на акке {selected_account}!\n🌟 | Описание промо: {promo_desc.replace('_', ' ')}\n{rcon_response.replace('(Экономика) Вы успешно выдали ', '💵 | Успешно выдано ')}")
                            else:
                                send_vk_message(vk_session, user_id, "🚫 | Ошибка при выдаче денег на сервере.\n{rcon_response.replace('(Экономика) ', '💵 | ')}")

                        promo_cursor.execute("UPDATE promo SET promo_count = promo_count - 1 WHERE promo_name = %s", (promo_code,))
                        promo_cursor.execute("INSERT INTO used_promo (promo_name, nickname) VALUES (%s, %s)", (promo_code, selected_account))
                        promo_conn.commit()

                    else:
                        send_vk_message(vk_session, user_id, "🚫 | Неверная команда Используйте /промо список|активация|создать.\n Используйте: /промо создать [доступ/привилегия] [ранг/привилегия] [количество использований] [описание]")
  #              else:
  #                  send_vk_message(user_id, message_id, "🚫 | Команда не распознана. Используйте "помощь" для списка команд.")
  
  #==================================#
  #                                  #
  #   При фатальной ошибки пытается  #   # воскресить бота                  #
  #                                  #
  #==================================#
  
    except Exception as e:
        print(f"Ошибка в основном цикле обработки событий: {e}")
        traceback.print_exc()
        time.sleep(5)  # Задержка перед перезапуском, чтобы избежать слишком частых перезапусков

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
            time.sleep(6)  # Задержка перед повторной попыткой

    print("Бот завершил работу.")