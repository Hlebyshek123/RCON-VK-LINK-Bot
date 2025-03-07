# ENCODING WITH UTF-8

import random
import sqlite3
import mysql.connector
import yaml
import os
from datetime import datetime, timedelta
import threading
import mcrcon
import string
import time
import traceback
import requests
from Bot import *

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

def get_server_permissions(rank):
    """Получаем список серверов, доступных для ранга пользователя."""
    return config['serv_perms'].get(rank, [])

def get_bot_allowed_ranks(bot_cmd):
    """Получаем список рангов, которые могут использовать Команды бота."""
    return config['allowed_ranks'].get(bot_cmd, [])

def get_bot_valid_ranks(valid):
    """Получаем список рангов, которые есть в боте."""
    return config['valid_ranks'].get(valid, [])
    
def get_rank_permissions(rank):
    if rank in rcon_ranks:
        allowed_commands = rcon_ranks[rank]
        if '*' in allowed_commands:
            return '*'
        return allowed_commands
    return []

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
    is_admin = "👑" if vk_id in config['admins'] else ""
    is_admin_desc = "👑 - Администратор Бота" if vk_id in config['admins'] else ""

    # Формируем итоговое сообщение
    player_info_message = f"""📝 | Найдена инфа по нику: {username}\n\n🔰 | ВК ID: @id{vk_id} {is_admin}\n🔑 | Доступ: {rank}\n🕹 | Всего наиграно: {hours} ч. {minutes} м.\n⛓️ | Привязанные акки: {accounts_list}\n🚫 | Бан в консоли: \n» Забанен: {banned}\n» Причина: {ban_reason}\n» Длительность: до {ban_time} MSK\n\n{is_admin_desc}"""
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
            send_vk_message(vk_session, user_id, "🚫 | Вы не выбрали сервер для подключения. \n » /rcon 1 [имя сервера].")
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
            send_vk_message(vk_session, user_id, f"✄1�7 | Вы успешно отправили команду на сервер {selected_server} (by {selected_account}) :\n📩 | сервер вернул пустой ответ")
    
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

#