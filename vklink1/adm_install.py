import sqlite3
import os

# Файл базы данных
DB_FILE = 'vk_bot.db'

# Проверка существования файла базы данных
def check_database_exists():
    if not os.path.exists(DB_FILE):
        print(":( | База данных не найдена. Пожалуйста, сначала запустите файл glavniy.py.")
        return False
    return True

# Соединение с базой данных
def connect_to_database():
    return sqlite3.connect(DB_FILE)

# Функция для добавления SuperAdmin
def add_superadmin(cursor, conn, vk_id, nickname):
    # Проверяем, есть ли уже пользователь с таким vk_id
    cursor.execute("SELECT vk_id FROM vk_rcon WHERE vk_id = ?", (vk_id,))
    existing_user = cursor.fetchone()

    if existing_user:
        print(f":( | Пользователь с vk_id {vk_id} уже существует в базе данных.")
    else:
        # Добавляем пользователя как SuperAdmin
        cursor.execute("INSERT INTO vk_rcon (vk_id, nickname, rank) VALUES (?, ?, ?)", (vk_id, nickname, 'SuperAdmin'))
        conn.commit()
        print(f":3 | Пользователь с vk_id {vk_id} и ником {nickname} добавлен как SuperAdmin.")
        print(f":3 | Теперь зайди на сервер введи /vkcode получи свой код и привяжи свой аккаунт к боту")

# Основная функция
def main():
    # Проверка наличия базы данных
    if not check_database_exists():
        return  # Завершаем работу, если база данных не найдена

    # Соединяемся с базой данных
    conn = connect_to_database()
    cursor = conn.cursor()

    # Запрашиваем у пользователя vk_id и nickname
    try:
        vk_id = int(input(":3 | Введите ваш ВК ID: "))
    except ValueError:
        print(":( | Ошибка: vk_id должен быть числом.")
        conn.close()  # Закрываем соединение с базой данных
        return

    nickname = input(":3 | Введите ваш никнейм: ")

    # Добавляем пользователя как SuperAdmin
    add_superadmin(cursor, conn, vk_id, nickname)

    # Закрываем соединение с базой данных
    conn.close()

if __name__ == "__main__":
    main()